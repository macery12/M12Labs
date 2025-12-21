<?php

namespace Everest\Http\Controllers\Api\Client;

use Everest\Models\Alert;
use Illuminate\Http\JsonResponse;

class AlertController extends ClientApiController
{
    /**
     * Get all active alerts for display to users.
     */
    public function index(): JsonResponse
    {
        $alerts = Alert::active()->get();

        return new JsonResponse($alerts);
    }
}
