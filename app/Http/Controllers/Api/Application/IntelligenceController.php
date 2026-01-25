<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\AI\OpenAIService;
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

            // If the key field is an empty string, delete the API key setting
            if ($key == 'key' && $value === '') {
                Setting::forget('settings::modules:ai:key');
                continue;
            }

            Setting::set('settings::modules:ai:' . $key, $value);
        }

        Activity::event('admin:ai:update')
            ->property('settings', $request->all())
            ->description('Jexactyl AI settings were updated')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Send a query to the AI service using OpenAI-compatible API.
     *
     * @throws \Throwable
     */
    public function query(Intelligence\QueryRequest $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
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
