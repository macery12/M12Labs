<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
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
     * Send an AI generated response to debug a server error.
     */
    public function index(Request $request, Server $server): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!config('modules.ai.enabled')) {
            throw new \Exception('The Jexactyl AI module is not enabled.');
        }

        // Check if streaming is requested
        if ($request->input('stream', false)) {
            return response()->stream(function () use ($request) {
                try {
                    foreach ($this->aiService->queryStream($request->input('query')) as $chunk) {
                        echo "data: " . json_encode(['content' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                    echo "data: [DONE]\n\n";
                    ob_flush();
                    flush();
                } catch (\Exception $e) {
                    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $result = $this->aiService->query($request->input('query'));

        return response()->json($result);
    }
}
