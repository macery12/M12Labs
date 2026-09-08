<?php

namespace Everest\Extensions\Packages\fixture_queue\Jobs;

use Everest\Extensions\Jobs\ExtensionJob;

/**
 * A deliberately slow extension job, used to exercise the parts of the queue
 * contract that only matter when work is genuinely in flight: the drain, the
 * concurrency cap and the uninstall refusal.
 *
 * It lives under the real package namespace because ExtensionJob derives the
 * owning extension from exactly that — a fixture in the test namespace would
 * prove nothing about how a shipped package behaves.
 */
class SlowFixtureJob extends ExtensionJob
{
    public function __construct(public int $sleepMicroseconds = 0)
    {
        parent::__construct();
    }

    public function queueGroup(): string
    {
        return 'slow';
    }

    public function handle(): void
    {
        if ($this->sleepMicroseconds > 0) {
            usleep($this->sleepMicroseconds);
        }
    }
}
