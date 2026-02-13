<?php

namespace Everest\Http\Controllers\Api\Client;

use Everest\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AlertController extends ClientApiController
{
    /**
     * Get all active alerts for display to users.
     * Optionally filter by scope and user.
     */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->input('scope', 'global');
        $user = $request->user();

        $alerts = Alert::active()
            ->forScope($scope)
            ->forUser($user->id)
            ->get();

        return new JsonResponse($alerts);
    }
}
