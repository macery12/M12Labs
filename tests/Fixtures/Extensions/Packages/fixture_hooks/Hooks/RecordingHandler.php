<?php

namespace Everest\Extensions\Packages\fixture_hooks\Hooks;

use Illuminate\Support\Facades\DB;
use Everest\Extensions\Hooks\HookHandler;

/**
 * A hook handler in a real package namespace, because that is what the
 * dispatcher resolves against — a handler in the test namespace would prove
 * nothing about how a shipped package behaves.
 *
 * It records what it saw and, importantly, how many of its own rows still
 * existed when it ran: that count is the whole point of the pre-delete slot.
 */
class RecordingHandler implements HookHandler
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    /** When set, the handler throws instead of recording. */
    public static bool $shouldThrow = false;

    public function handle(array $envelope): void
    {
        if (self::$shouldThrow) {
            throw new \RuntimeException('Deliberate handler failure.');
        }

        $serverId = $envelope['payload']['serverId'] ?? null;

        self::$calls[] = [
            'event' => $envelope['event'],
            'correlationId' => $envelope['correlationId'],
            'payload' => $envelope['payload'],
            // Rows this handler owns that are still readable right now.
            'ownRowsVisible' => $serverId === null
                ? null
                : DB::table('ext_fixture_hooks_rows')->where('server_id', $serverId)->count(),
        ];
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$shouldThrow = false;
    }
}
