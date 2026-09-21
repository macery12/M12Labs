<?php

namespace Everest\Extensions\Jobs;

use Illuminate\Foundation\Bus\PendingDispatch;
use Everest\Services\Extensions\ExtensionQueueJournal;

/**
 * Reconciles a durable queue reservation when the backend push itself throws.
 */
final class ExtensionPendingDispatch extends PendingDispatch
{
    public function __destruct()
    {
        try {
            parent::__destruct();
        } catch (\Throwable $exception) {
            try {
                if ($this->job instanceof ExtensionJob) {
                    app(ExtensionQueueJournal::class)->pushFailed($this->job);
                }
            } catch (\Throwable) {
                // Preserve the backend exception. A failed reconciliation
                // leaves a conservative in-flight row instead of widening the
                // quota or hiding work from lifecycle drain checks.
            }

            throw $exception;
        }
    }
}
