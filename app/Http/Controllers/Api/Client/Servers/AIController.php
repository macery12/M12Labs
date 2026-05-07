<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Everest\Models\Setting;
use Everest\Models\AiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Everest\Services\AI\OpenAIService;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class AIController extends ClientApiController
{
    /**
     * AIController constructor.
     */
    public function __construct(private OpenAIService $aiService)
    {
        parent::__construct();
    }

    /**
     * Build a structured, context-aware prompt for the AI.
     * Injecting server metadata significantly improves response quality,
     * especially for small local models that need clear framing.
     */
    private function buildPrompt(Server $server, string $rawQuery, string $queryType): string
    {
        $server->loadMissing('egg');
        $eggName = $server->egg?->name ?? 'Unknown';
        $status = $server->status ?? 'running';
        $context = "Server: {$server->name} | Type: {$eggName} | Status: {$status} | Memory limit: {$server->memory}MB | Disk limit: {$server->disk}MB | CPU limit: {$server->cpu}%";

        if ($queryType === 'log_analysis') {
            return <<<PROMPT
You are diagnosing a game server that has stopped or crashed.

{$context}

Recent console output (last lines before shutdown):
---
{$rawQuery}
---

Instructions:
- Read the log carefully and identify the SPECIFIC error causing the problem — quote the exact log line that proves it.
- Do NOT give generic "server overloaded" or "check your config" advice unless those words literally appear in the log.
- If you see a EULA, license agreement, or first-run setup message, say so clearly — this is NOT a crash.
- If you see an out-of-memory kill, Java heap error, missing file, port conflict, or plugin exception, name it specifically.
- Format your response as:
  **Issue:** [one sentence — what exactly went wrong]
  **Evidence:** [the exact log line that shows the problem]
  **Fix:** [numbered steps specific to this issue]
PROMPT;
        }

        // Freeform — include the tail of recent logs as evidence so the AI works
        // from real data instead of guessing. The frontend passes log data as the
        // query for log_analysis; for freeform we inject it as context here.
        $logContext = strlen($rawQuery) > 200
            ? '' // rawQuery is already a big block of logs — treat as log_analysis
            : ''; // will be set below via request log if we ever thread it through

        return <<<PROMPT
You are a game server support assistant with access to this server's context.

{$context}

User question: {$rawQuery}

Instructions:
- If the question is not related to game servers, server administration, or this specific server — for example greetings, tests, jokes, or random chat — respond ONLY with: "I'm sorry, I'm only able to help with server-related questions. Try asking about crashes, performance, configuration, or how to manage your {$eggName} server."
- Answer SPECIFICALLY for this server type ({$eggName}) and its current state (status: {$status}).
- If the question is about performance or crashes, ask yourself: what are the most common causes for {$eggName} servers specifically?
- Do NOT give generic Linux server advice. Be specific to {$eggName}.
- If you cannot give a specific answer without more information, ask ONE clarifying question.
- Keep your response concise and actionable.
PROMPT;
    }

    /**
     * Send an AI generated response to debug a server error.
     */
    public function index(Request $request, Server $server): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $enabled = filter_var(
            Setting::get('settings::modules:ai:enabled', config('modules.ai.enabled', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            abort(403, 'The M12Labs-AI module is not enabled.');
        }

        // Admins always have access; regular users need user_access enabled
        $userAccess = filter_var(
            Setting::get('settings::modules:ai:user_access', config('modules.ai.user_access', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$userAccess && !$request->user()->root_admin && !$request->user()->admin_role_id) {
            abort(403, 'AI access has not been enabled for standard users.');
        }

        // Rate limiting: admins get 60 req/10min, regular users get 15 req/10min
        $isPrivileged = $request->user()->root_admin || $request->user()->admin_role_id;
        $maxAttempts = $isPrivileged ? 60 : 15;
        $rateLimitKey = 'ai:' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'error' => 'Too many AI requests. Please try again in ' . $retryAfter . ' seconds.',
                'retry_after' => $retryAfter,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 600); // 10-minute window

        $rawQuery = $request->input('query', '');
        $queryType = $request->input('query_type', 'freeform');

        // Multi-turn: accept an optional history array from the frontend.
        // Each element must be {role: 'user'|'assistant', content: string}.
        // The current query is always the last user message.
        $history = $request->input('messages', []);
        $safeHistory = [];
        if (is_array($history)) {
            foreach (array_slice($history, -10) as $msg) {
                if (isset($msg['role'], $msg['content'])
                    && in_array($msg['role'], ['user', 'assistant'], true)
                    && is_string($msg['content'])
                ) {
                    $safeHistory[] = ['role' => $msg['role'], 'content' => substr($msg['content'], 0, 4000)];
                }
            }
        }

        // Build the context-injected prompt for the final user message, then append to history
        $finalUserContent = $this->buildPrompt($server, $rawQuery, $queryType);
        // Replace the last user message in history with the context-enriched version,
        // or append it if there's no history yet.
        if (!empty($safeHistory) && end($safeHistory)['role'] === 'user') {
            $safeHistory[count($safeHistory) - 1]['content'] = $finalUserContent;
        } else {
            $safeHistory[] = ['role' => 'user', 'content' => $finalUserContent];
        }

        $streamOptions = ['messages' => $safeHistory];

        // Capture values needed inside closures / catch blocks.
        $userId = $request->user()->id;
        $serverUuid = $server->uuid;
        $conversationId = $request->input('conversation_id') ? (int) $request->input('conversation_id') : null;
        $model = Setting::get('settings::modules:ai:model', config('modules.ai.model', 'unknown'));

        // Check if streaming is requested
        if ($request->input('stream', false)) {
            return response()->stream(function () use ($streamOptions, $userId, $serverUuid, $conversationId, $model) {
                // Prevent PHP-FPM from killing a long-running AI request and allow
                // the response to finish even if the client disconnects early.
                set_time_limit(0);
                ignore_user_abort(true);

                // Flush an SSE keep-alive comment immediately so nginx/proxies
                // don't 504 while waiting for the first AI token.
                echo ": keep-alive\n\n";
                ob_flush();
                flush();

                $start = microtime(true);
                $status = 'success';
                $errorMsg = null;

                try {
                    foreach ($this->aiService->queryStream('', $streamOptions) as $chunk) {
                        echo 'data: ' . json_encode(['content' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                    echo "data: [DONE]\n\n";
                    ob_flush();
                    flush();
                } catch (\Exception $e) {
                    $status = 'error';
                    $errorMsg = $e->getMessage();
                    echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
                    ob_flush();
                    flush();
                }

                $latencyMs = (int) round((microtime(true) - $start) * 1000);
                try {
                    AiUsageLog::create([
                        'user_id' => $userId,
                        'server_uuid' => $serverUuid,
                        'conversation_id' => $conversationId,
                        'model' => $model,
                        'source' => 'client',
                        'latency_ms' => $latencyMs,
                        'status' => $status,
                        'error_message' => $errorMsg,
                    ]);
                } catch (\Exception $logEx) {
                    Log::warning('Failed to write AI usage log: ' . $logEx->getMessage());
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $prompt = $this->buildPrompt($server, $rawQuery, $queryType);
        $start = microtime(true);

        try {
            $result = $this->aiService->query($prompt);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $usage = $this->aiService->getLastUsage();

            try {
                AiUsageLog::create([
                    'user_id' => $userId,
                    'server_uuid' => $serverUuid,
                    'conversation_id' => $conversationId,
                    'model' => $usage['model'] ?? $model,
                    'source' => 'client',
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                    'total_tokens' => $usage['total_tokens'] ?? null,
                    'latency_ms' => $latencyMs,
                    'status' => 'success',
                ]);
            } catch (\Exception $logEx) {
                Log::warning('Failed to write AI usage log: ' . $logEx->getMessage());
            }

            return response()->json($result);
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            try {
                AiUsageLog::create([
                    'user_id' => $userId,
                    'server_uuid' => $serverUuid,
                    'conversation_id' => $conversationId,
                    'model' => $model,
                    'source' => 'client',
                    'latency_ms' => $latencyMs,
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
            } catch (\Exception $logEx) {
                Log::warning('Failed to write AI usage log: ' . $logEx->getMessage());
            }
            throw $e;
        }
    }
}
