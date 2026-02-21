<?php

namespace Everest\Services\Auth;

use Carbon\CarbonImmutable;
use Everest\Events\Email\NewLoginDetected;
use Everest\Models\User;
use Everest\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session as SessionFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserSessionService
{
    public const DEVICE_COOKIE = 'everest_device_id';

    public function __construct(private Request $request)
    {
    }

    /**
    * Record or update a user session on login and trigger notification if needed.
    */
    public function recordLogin(User $user, string $sessionId, string &$deviceId): UserSession
    {
        $deviceId = $deviceId ?: Str::uuid()->toString();
        $fingerprint = $this->fingerprint($deviceId);
        $now = CarbonImmutable::now();
        Log::info('UserSessionService: recordLogin start', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'fingerprint' => $fingerprint,
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ]);

        $existingForFingerprint = UserSession::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->orderByDesc('created_at')
            ->first();

        $session = UserSession::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ],
            [
                'device_fingerprint' => $fingerprint,
                'device_name' => $this->deviceName(),
                'user_agent' => $this->userAgent(),
                'ip_address' => $this->ip(),
                'location' => $this->location(),
                'last_activity_at' => $now,
                'revoked_at' => null,
            ]
        );

        $shouldNotify = $this->shouldNotify($existingForFingerprint);

        if (!$shouldNotify && $existingForFingerprint && $existingForFingerprint->last_notified_at && !$session->last_notified_at) {
            $session->forceFill(['last_notified_at' => $existingForFingerprint->last_notified_at])->save();
        }

        if ($shouldNotify) {
            $session->forceFill(['last_notified_at' => $now])->save();
            // Generate a unique correlation ID per email send to keep delivery logs distinct.
            $correlationId = Str::uuid()->toString();

            event(new NewLoginDetected(
                $user,
                $this->ip(),
                $this->userAgent(),
                $correlationId,
                $now,
                $this->location()
            ));
            Log::info('UserSessionService: new login notification dispatched', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'device_id' => $deviceId,
                'correlation_id' => $correlationId,
            ]);
        }

        Log::info('UserSessionService: recordLogin stored', [
            'user_id' => $user->id,
            'session_db_id' => $session->id,
            'created_at' => $session->created_at,
            'last_activity_at' => $session->last_activity_at,
            'revoked_at' => $session->revoked_at,
        ]);

        return $session;
    }

    /**
     * Update last activity metadata for current request.
     */
    public function updateActivity(User $user, string $sessionId): void
    {
        $updated = UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->update([
                'last_activity_at' => CarbonImmutable::now(),
                'ip_address' => $this->ip(),
                'user_agent' => $this->userAgent(),
            ]);

        if (!$updated) {
            Log::warning('UserSessionService: updateActivity found no session', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Revoke a single session and destroy the backing session storage.
     */
    public function revokeSession(User $user, UserSession $session, bool $destroy = true): void
    {
        if ($session->user_id !== $user->id) {
            Log::warning('UserSessionService: revokeSession blocked for mismatched user', [
                'user_id' => $user->id,
                'session_user_id' => $session->user_id,
                'session_id' => $session->id,
            ]);
            return;
        }

        $session->update(['revoked_at' => CarbonImmutable::now()]);

        if ($destroy) {
            SessionFacade::getHandler()->destroy($session->session_id);
        }

        Log::info('UserSessionService: session revoked', [
            'user_id' => $user->id,
            'session_db_id' => $session->id,
            'destroyed' => $destroy,
        ]);
    }

    /**
     * Convenience helper to revoke using a session id.
     */
    public function revokeBySessionId(User $user, string $sessionId, bool $destroy = true): void
    {
        $session = UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->first();

        if ($session) {
            $this->revokeSession($user, $session, $destroy);
        }
    }

    /**
     * Revoke all sessions for the user, optionally keeping the provided session.
     */
    public function revokeAll(User $user, ?string $exceptSessionId = null): void
    {
        $sessions = UserSession::query()
            ->where('user_id', $user->id)
            ->when($exceptSessionId, fn ($q) => $q->where('session_id', '!=', $exceptSessionId))
            ->get();

        foreach ($sessions as $session) {
            $this->revokeSession($user, $session);
        }

        Log::info('UserSessionService: revokeAll complete', [
            'user_id' => $user->id,
            'count' => $sessions->count(),
            'except_session' => $exceptSessionId,
        ]);
    }

    /**
     * Build a stable fingerprint using device id + /24 IP + user agent.
     */
    protected function fingerprint(string $deviceId): string
    {
        $ip = $this->ip();
        $ipKey = $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $ipKey = implode('.', array_slice($parts, 0, 3));
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipKey = substr($ip, 0, 19);
        }

        return hash('sha256', implode('|', [$deviceId, strtolower($this->userAgent()), $ipKey]));
    }

    protected function shouldNotify(?UserSession $session): bool
    {
        if (!$session) {
            return true;
        }

        $cooldownHours = (int) config('modules.auth.security.login_notification_cooldown_hours', 24);
        if (!$session->last_notified_at) {
            return true;
        }

        return $session->last_notified_at->lte(CarbonImmutable::now()->subHours($cooldownHours));
    }

    protected function userAgent(): string
    {
        return (string) ($this->request->userAgent() ?: 'Unknown device');
    }

    protected function ip(): string
    {
        return (string) ($this->request->ip() ?: 'Unknown');
    }

    protected function location(): ?string
    {
        return null;
    }

    protected function deviceName(): ?string
    {
        $ua = $this->userAgent();

        // Rough device label fallback.
        if (str_contains($ua, '(')) {
            return trim(substr($ua, 0, 120));
        }

        return $ua ? mb_substr($ua, 0, 120) : null;
    }
}
