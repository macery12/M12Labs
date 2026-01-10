<?php

namespace Everest\Http\Controllers\Api\Client;

use Stripe\StripeClient;
use Everest\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\BillingException;

/**
 * Donation Controller
 * 
 * Handles donation processing using the existing Stripe integration.
 * Donations do not provide any server benefits - they are simple monetary contributions.
 */
class DonationController extends ClientApiController
{
    private ?StripeClient $stripe = null;

    public function __construct()
    {
        parent::__construct();

        // Initialize Stripe client if secret key is configured
        $stripeSecret = config('modules.billing.keys.secret');
        if ($stripeSecret) {
            $this->stripe = new StripeClient($stripeSecret);
        }
    }

    /**
     * Get Stripe public key for donations.
     * 
     * @return JsonResponse
     */
    public function getStripeKey(): JsonResponse
    {
        $publicKey = (string) config('modules.billing.keys.publishable') ?? null;

        if (!$publicKey) {
            throw new DisplayException('Stripe is not configured. Please contact an administrator.');
        }

        return response()->json(['key' => $publicKey]);
    }

    /**
     * Create a Stripe payment intent for donation.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createIntent(Request $request): JsonResponse
    {
        $this->ensureStripeInitialized();

        $request->validate([
            'amount' => 'required|numeric|min:1|max:10000',
            'message' => 'nullable|string|max:500',
        ]);

        $amount = (float) $request->input('amount');
        $currency = strtolower(config('modules.billing.currency.code', 'usd'));

        $paymentMethodTypes = ['card'];

        if (config('modules.billing.paypal')) {
            $paymentMethodTypes[] = 'paypal';
        }

        if (config('modules.billing.link')) {
            $paymentMethodTypes[] = 'link';
        }

        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount' => (int)($amount * 100), // Convert to cents
            'currency' => $currency,
            'payment_method_types' => array_values($paymentMethodTypes),
            'metadata' => [
                'type' => 'donation',
                'user_id' => (string) $request->user()->id,
                'user_email' => $request->user()->email,
                'message' => $request->input('message', ''),
            ],
        ]);

        if (!$paymentIntent->client_secret) {
            throw new DisplayException('Failed to create payment intent. Please try again.');
        }

        // Create donation record
        Donation::create([
            'user_id' => $request->user()->id,
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => Donation::STATUS_PENDING,
            'message' => $request->input('message'),
        ]);

        return response()->json([
            'id' => $paymentIntent->id,
            'secret' => $paymentIntent->client_secret,
        ]);
    }

    /**
     * Complete a donation after payment is confirmed.
     * 
     * @param Request $request
     * @return Response
     */
    public function complete(Request $request): Response
    {
        $this->ensureStripeInitialized();

        $request->validate([
            'intent' => 'required|string',
        ]);

        $intent = $this->stripe->paymentIntents->retrieve($request->input('intent'));

        if (!$intent) {
            throw new DisplayException('Payment intent not found.');
        }

        $donation = Donation::where('payment_intent_id', $intent->id)->first();

        if (!$donation) {
            throw new DisplayException('Donation record not found.');
        }

        // Check if donation has already been processed
        if ($donation->status === Donation::STATUS_COMPLETED) {
            throw new DisplayException('This donation has already been processed.');
        }

        // If the payment wasn't successful, mark the donation as failed
        if ($intent->status !== 'succeeded') {
            $donation->update(['status' => Donation::STATUS_FAILED]);
            throw new DisplayException('The donation payment failed.');
        }

        // Mark the donation as completed
        $donation->update(['status' => Donation::STATUS_COMPLETED]);

        return $this->returnNoContent();
    }

    /**
     * Get user's donation history.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $donations = $request->user()
            ->donations()
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($donations);
    }

    /**
     * Ensure Stripe client is initialized.
     * 
     * @throws DisplayException if Stripe is not configured
     */
    private function ensureStripeInitialized(): void
    {
        if (!$this->stripe) {
            throw new DisplayException('Stripe is not configured. Please contact an administrator.');
        }
    }
}
