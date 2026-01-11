<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Illuminate\Http\JsonResponse;
use Everest\Models\Donation;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Illuminate\Http\Request;

class DonationController extends ApplicationApiController
{
    /**
     * Get all donations for admin view.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $donations = Donation::with('user:id,username,email')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($donations);
    }
}
