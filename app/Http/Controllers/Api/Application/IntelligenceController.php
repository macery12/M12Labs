<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Setting;
use Everest\Models\AiUsageLog;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Everest\Services\AI\OpenAIService;
use Everest\Services\Email\EmailRedactor;
use Everest\Http\Requests\Api\Application\Intelligence;

class IntelligenceController extends ApplicationApiController
{
    /**
     * IntelligenceController constructor.
     */
    public function __construct(private OpenAIService $aiService)
    {
        parent::__construct();
    }

    /**
     * Update the AI settings for the Panel.
     *
     * @throws \Throwable
     */
    public function update(Intelligence\UpdateIntelligenceSettingsRequest $request): Response
    {
        foreach ($request->normalize() as $key => $value) {
            if ($key == 'key' && is_bool($value)) {
                continue;
            }

            Setting::set('settings::modules:ai:' . $key, $value);
        }

        $activitySettings = EmailRedactor::redactSensitivePayload(
            $request->all(),
            ['api_key', 'token', 'secret', 'password', 'authorization', 'key']
        );

        Activity::event('admin:ai:update')
            ->property('settings', $activitySettings)
            ->description('M12Labs-AI settings were updated')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Test the connection to the configured AI endpoint.
     */
    public function testConnection(): JsonResponse
    {
        $start = microtime(true);

        try {
            $ok = $this->aiService->testConnection();
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            if ($ok) {
                return response()->json(['status' => 'ok', 'latency_ms' => $latencyMs]);
            }

            return response()->json(['status' => 'error', 'message' => 'AI service returned an unexpected response.'], 502);
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            return response()->json(['status' => 'error', 'message' => $e->getMessage(), 'latency_ms' => $latencyMs], 502);
        }
    }

    /**
     * Send a query to the AI service using OpenAI-compatible API.
     *
     * @throws \Throwable
     */
    public function query(Intelligence\QueryRequest $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!config('modules.ai.enabled')) {
            throw new \Exception('The M12Labs-AI module is not enabled.');
        }

        // Check if streaming is requested
        if ($request->input('stream', false)) {
            $userId = $request->user()?->id;
            $model = Setting::get('settings::modules:ai:model', config('modules.ai.model', 'unknown'));

            return response()->stream(function () use ($request, $userId, $model) {
                $start = microtime(true);
                $status = 'success';
                $errorMsg = null;

                try {
                    foreach ($this->aiService->queryStream($request->input('query')) as $chunk) {
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
                        'model' => $model,
                        'source' => 'admin',
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

        $model = Setting::get('settings::modules:ai:model', config('modules.ai.model', 'unknown'));
        $start = microtime(true);

        try {
            $result = $this->aiService->query($request->input('query'));
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $usage = $this->aiService->getLastUsage();

            try {
                AiUsageLog::create([
                    'user_id' => $request->user()?->id,
                    'model' => $usage['model'] ?? $model,
                    'source' => 'admin',
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
                    'user_id' => $request->user()?->id,
                    'model' => $model,
                    'source' => 'admin',
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
