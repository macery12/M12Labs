<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Everest\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
        $context = "Server: {$server->name} | Type: {$eggName} | Status: {$status} | Memory: {$server->memory}MB | Disk: {$server->disk}MB | CPU: {$server->cpu}%";

        if ($queryType === 'log_analysis') {
            return "Server context: {$context}\n\nConsole output:\n---\n{$rawQuery}\n---\n\nIdentify the root cause of the crash/error and provide specific numbered steps to fix it.";
        }

        // Freeform question — still include server context so the model can give relevant advice
        return "Server context: {$context}\n\nQuestion: {$rawQuery}";
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
            abort(403, 'The Jexactyl AI module is not enabled.');
        }

        // Admins always have access; regular users need user_access enabled
        $userAccess = filter_var(
            Setting::get('settings::modules:ai:user_access', config('modules.ai.user_access', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$userAccess && !$request->user()->root_admin && !$request->user()->admin_role_id) {
            abort(403, 'AI access has not been enabled for standard users.');
        }

        $rawQuery = $request->input('query', '');
        $queryType = $request->input('query_type', 'freeform');
        $prompt = $this->buildPrompt($server, $rawQuery, $queryType);

        // Check if streaming is requested
        if ($request->input('stream', false)) {
            return response()->stream(function () use ($prompt) {
                try {
                    foreach ($this->aiService->queryStream($prompt) as $chunk) {
                        echo 'data: ' . json_encode(['content' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                    echo "data: [DONE]\n\n";
                    ob_flush();
                    flush();
                } catch (\Exception $e) {
                    echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $result = $this->aiService->query($prompt);

        return response()->json($result);
    }
}
