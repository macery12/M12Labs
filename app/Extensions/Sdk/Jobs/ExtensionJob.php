<?php

namespace Everest\Extensions\Sdk\Jobs;

use Everest\Extensions\Jobs\ExtensionJob as Core;

/**
 * Base class for an extension's queued work.
 *
 * Everything that decides how the job is treated — queue, tries, timeout,
 * backoff, uniqueness, and the owning extension id — comes from the verified
 * manifest and from the class namespace, never from the subclass. In
 * particular `extensionId()` is final on the core class and reads
 * `static::class`, so a package job under `Everest\Extensions\Packages\<id>\`
 * still resolves to its own extension through this indirection.
 *
 * Implement `queueGroup()` with a name the manifest declares under
 * `capabilities.queues`, and `handle()`.
 */
abstract class ExtensionJob extends Core
{
}
