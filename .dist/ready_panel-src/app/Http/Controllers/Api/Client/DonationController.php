<?php

namespace Everest\Http\Controllers\Api\Client;

use Stripe\StripeClient;
use Everest\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Exceptions\DisplayException;

/**
 * Donation Controller.
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
     * This endpoint is safe to call from the frontend as it only returns
     * the publishable key, which is meant to be public.
     *
     * @throws DisplayException if publishable key is missing or appears to be a secret key
     */
    public function getStripeKey(): JsonResponse
    {
        $publicKey = config('modules.billing.keys.publishable');

        if (empty($publicKey)) {
            throw new DisplayException('Stripe is not configured. Please contact an administrator.');
        }

        // SECURITY: Verify this is actually a publishable key, not a secret key
        // Publishable keys start with 'pk_', secret keys start with 'sk_'
        if (str_starts_with($publicKey, 'sk_')) {
            // Log this critical security issue
            \Log::critical('SECURITY: Secret key detected in publishable key field (donations)!', [
                'detected_type' => 'secret_key',
            ]);

            throw new DisplayException('Critical configuration error: A secret key has been detected in the publishable key field. This is a severe security risk. Please reconfigure your Stripe keys immediately with the correct key types.');
        }

        // Verify it looks like a valid Stripe publishable key
        if (!str_starts_with($publicKey, 'pk_')) {
            throw new DisplayException('Invalid Stripe publishable key format. Publishable keys must start with \'pk_test_\' or \'pk_live_\'.');
        }

        return response()->json(['key' => $publicKey]);
    }

    /**
     * Create a Stripe payment intent for donation.
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
            'amount' => (int) ($amount * 100), // Convert to cents
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
        $message = $request->input('message');
        $message = ($message && trim($message) !== '') ? trim($message) : null;

        Donation::create([
            'user_id' => $request->user()->id,
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => Donation::STATUS_PENDING,
            'message' => $message,
        ]);

        return response()->json([
            'id' => $paymentIntent->id,
            'secret' => $paymentIntent->client_secret,
        ]);
    }

    /**
     * Complete a donation after payment is confirmed.
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
     */
    public function index(Request $request): JsonResponse
    {
        $donations = $request->user()
            ->donations()
            ->with('user:id,username,email')
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
