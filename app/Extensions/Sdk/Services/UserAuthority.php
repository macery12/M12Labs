<?php

namespace Everest\Extensions\Sdk\Services;

use IPTools\IP;
use IPTools\Range;
use Everest\Models\User;
use Everest\Models\ApiKey;
use Everest\Models\UserSession;

/**
 * Whether the authority that started a piece of work still holds.
 *
 * For queued work that outlives the request which scheduled it. A job running
 * as a user five minutes later is acting on an authorization nobody has
 * re-checked, and the three ways it lapses are all deliberate acts somebody
 * expects to take effect immediately: the account was deleted or suspended,
 * the device was signed out, or the API key was revoked. A password reset
 * arrives through the second, because revoking sessions is how the panel
 * expresses one.
 *
 * Ordinary request-time work needs none of this -- the middleware stack has
 * already asked. This is for the gap between accepting work and doing it.
 *
 * Booleans and an id, never the session or key record. A package has no reason
 * to read a session row, and an API key record carries a secret that must not
 * be serialised into a job payload; what a caller needs is permission to
 * continue, which is a yes or a no.
 */
final class UserAuthority
{
    public static function reader(): self
    {
        return new self();
    }

    /**
     * Whether the account itself can still act.
     *
     * A deleted user answers false rather than throwing, because "the person
     * this was for is gone" is an ordinary outcome for queued work and not an
     * error in the caller.
     */
    public function accountActive(int $userId): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User && !$user->isSuspended();
    }

    /** Whether this browser session is still signed in and unrevoked. */
    public function sessionActive(int $userId, string $sessionId): bool
    {
        return UserSession::query()
            ->where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->active()
            ->exists();
    }

    /**
     * Whether this API key still exists, still belongs to the user, has not
     * expired, and -- when it is pinned to an address range -- would still be
     * accepted from `$ip`.
     *
     * The address check is here rather than left to the caller because it is
     * part of what the key means. A package asking "may this key still act"
     * and getting yes for a key that is pinned to an office range, from a
     * queue worker, would be told the wrong thing. Passing no address against
     * a pinned key answers false: an unknown origin cannot be inside a range.
     */
    public function apiKeyActive(int $userId, int $apiKeyId, ?string $ip = null): bool
    {
        $key = ApiKey::query()
            ->whereKey($apiKeyId)
            ->where('user_id', $userId)
            ->first();

        if (!$key instanceof ApiKey) {
            return false;
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return false;
        }

        if (empty($key->allowed_ips)) {
            return true;
        }

        if ($ip === null) {
            return false;
        }

        try {
            $origin = new IP($ip);

            foreach ($key->allowed_ips as $allowed) {
                if (Range::parse($allowed)->contains($origin)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * The key's identifier token, for rebuilding a request's authentication
     * without carrying the secret.
     *
     * Returns the public half only. The secret never leaves the panel through
     * here, which is the point: a queued job holds an id and asks for what it
     * needs, rather than holding a credential for as long as it sits in a
     * queue.
     */
    public function apiKeyIdentifier(int $userId, int $apiKeyId): ?string
    {
        $identifier = ApiKey::query()
            ->whereKey($apiKeyId)
            ->where('user_id', $userId)
            ->value('identifier');

        return is_string($identifier) ? $identifier : null;
    }
}
