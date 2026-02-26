<?php

namespace Everest\Http\Controllers\Auth;

use Everest\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Everest\Http\Controllers\Controller;

class VerifyEmailController extends Controller
{
    /**
     * Handle email verification link callbacks.
     */
    public function __invoke(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals(sha1($user->email), $hash) || !$request->hasValidSignature()) {
            abort(Response::HTTP_FORBIDDEN, 'Invalid or expired verification link.');
        }

        $wasJustVerified = $user->markEmailAsVerified();

        if (Auth::check() && Auth::id() === $user->id) {
            Auth::setUser($user->fresh());
        }

        $messageType = $wasJustVerified ? 'success' : 'info';
        $message = $wasJustVerified
            ? 'Your email has been verified successfully.'
            : 'Your email was already verified.';

        return redirect()->to('/')->with($messageType, $message);
    }
}
