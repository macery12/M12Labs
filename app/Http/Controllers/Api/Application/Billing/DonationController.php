<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Everest\Models\Donation;
use Illuminate\Http\JsonResponse;
use Everest\Http\Requests\Api\Application\Billing\Donations\GetDonationsRequest;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;

class DonationController extends ApplicationApiController
{
    /**
     * Get all donations for admin view.
     */
    public function index(GetDonationsRequest $request): JsonResponse
    {
        $donations = Donation::with('user:id,username,email')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($donations);
    }
}
