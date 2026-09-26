<?php

namespace Everest\Http\Controllers\Api;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Dedoc\Scramble\Generator;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\Api\ApiDocsCache;
use Everest\Http\Controllers\Controller;

class ApiDocsController extends Controller
{
    /**
     * The spec when one is cached; otherwise 202 while GenerateApiDocsJob runs
     * (the page polls), or 503 with the reason the last run failed.
     */
    public function json(Request $request, Generator $generator, ApiDocsCache $docs): JsonResponse
    {
        // Development switch: generate inline, as before generation moved to
        // the queue. Needs more than php-fpm's default 128M.
        if (!config('api-docs.cache.enabled', true)) {
            return response()->json($generator());
        }

        if ($request->boolean('refresh')) {
            $docs->forget();
        }

        $entry = $docs->current();
        $failure = $docs->failure();

        // A recorded failure is only retried by Regenerate, never by the page's
        // own polling, or a route Scramble can't analyse would requeue forever.
        if (($entry === null && $failure === null) || ($entry !== null && $docs->isStale($entry))) {
            $docs->queue();
        }

        if ($entry !== null) {
            return response()->json($entry['spec']);
        }

        if ($failure !== null) {
            return response()->json([
                'status' => 'failed',
                'message' => $failure,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'status' => 'generating',
            'waited_seconds' => $docs->pendingFor() ?? 0,
        ], Response::HTTP_ACCEPTED);
    }

    public function docs(): View
    {
        return view('scramble::docs');
    }
}
