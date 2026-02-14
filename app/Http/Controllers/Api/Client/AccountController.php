<?php

namespace Everest\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Everest\Services\Users\UserUpdateService;
use Everest\Transformers\Api\Client\AccountTransformer;
use Everest\Http\Requests\Api\Client\Account\SetupUserRequest;
use Everest\Http\Requests\Api\Client\Account\UpdateEmailRequest;
use Everest\Http\Requests\Api\Client\Account\UpdatePasswordRequest;
use Everest\Exceptions\DisplayException;

class AccountController extends ClientApiController
{
    /**
     * AccountController constructor.
     */
    public function __construct(private AuthManager $manager, private UserUpdateService $updateService)
    {
        parent::__construct();
    }

    public function index(Request $request): array
    {
        return $this->fractal->item($request->user())
            ->transformWith(AccountTransformer::class)
            ->toArray();
    }

    /**
     * Update the authenticated user's email address.
     */
    public function updateEmail(UpdateEmailRequest $request): JsonResponse
    {
        $original = $request->user()->email;
        $this->updateService->handle($request->user(), $request->validated());

        if ($original !== $request->input('email')) {
            Activity::event('user:account.email-changed')
                ->property(['old' => $original, 'new' => $request->input('email')])
                ->log();
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Update the authenticated user's password. All existing sessions will be logged
     * out immediately.
     *
     * @throws \Throwable
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $this->updateService->handle($request->user(), $request->validated());

        $guard = $this->manager->guard();
        // If you do not update the user in the session you'll end up working with a
        // cached copy of the user that does not include the updated password. Do this
        // to correctly store the new user details in the guard and allow the logout
        // other devices functionality to work.
        $guard->setUser($user);

        // This method doesn't exist in the stateless Sanctum world.
        if (method_exists($guard, 'logoutOtherDevices')) {
            $guard->logoutOtherDevices($request->input('password'));
        }

        Activity::event('user:account.password-changed')->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Set up an account when registered with OAuth2.
     */
    public function setup(SetupUserRequest $request): JsonResponse
    {
        $user = $this->updateService->handle($request->user(), $request->validated());

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
    
    /**
     * Get the recovery code for the authenticated user.
     * This can only be downloaded once - after that it's marked as viewed.
     */
    public function getRecoveryCode(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user->recovery_code) {
            return new JsonResponse(['error' => 'No recovery code available'], Response::HTTP_NOT_FOUND);
        }
        
        // Check if already downloaded (we'll use a session flag for this)
        if ($request->session()->has('recovery_code_downloaded_' . $user->id)) {
            return new JsonResponse(['error' => 'Recovery code has already been downloaded'], Response::HTTP_FORBIDDEN);
        }
        
        try {
            $recoveryCode = Crypt::decryptString($user->recovery_code);
            
            // Mark as downloaded
            $request->session()->put('recovery_code_downloaded_' . $user->id, true);
            
            Activity::event('user:recovery-code.viewed')
                ->withRequestMetadata()
                ->log();
            
            return new JsonResponse([
                'recovery_code' => $recoveryCode,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Unable to retrieve recovery code'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Check if the recovery code has been downloaded.
     */
    public function checkRecoveryCodeStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $hasRecoveryCode = !empty($user->recovery_code);
        $alreadyDownloaded = $request->session()->has('recovery_code_downloaded_' . $user->id);
        
        return new JsonResponse([
            'has_recovery_code' => $hasRecoveryCode,
            'already_downloaded' => $alreadyDownloaded,
            'can_download' => $hasRecoveryCode && !$alreadyDownloaded,
        ]);
    }
    
    /**
     * Unlink Discord account.
     */
    public function unlinkDiscord(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (empty($user->external_id)) {
            throw new DisplayException('No Discord account is linked to your account.');
        }
        
        // Remove the external_id to unlink Discord
        $this->updateService->handle($user, ['external_id' => null]);
        
        Activity::event('user:discord.unlinked')
            ->withRequestMetadata()
            ->log();
        
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
