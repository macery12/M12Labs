<?php

namespace Everest\Extensions\Hooks;

/**
 * Implemented by a package class named in capabilities.hooks[].handler.
 *
 * The dispatcher resolves nothing that does not implement this, is not
 * declared, and does not live in the package's own Hooks namespace. There is no
 * registrar and no package boot file: every subscription is declarative and
 * verified at install, which is what keeps a package from running code on every
 * request of the panel's life.
 */
interface HookHandler
{
    /**
     * @param array<string, mixed> $envelope as built by HookEvent::envelope()
     */
    public function handle(array $envelope): void;
}
