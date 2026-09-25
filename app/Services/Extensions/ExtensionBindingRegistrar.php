<?php

namespace Everest\Services\Extensions;

use Illuminate\Contracts\Container\Container;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;

/**
 * Registers the container bindings installed packages declared.
 *
 * Packages cannot supply a service provider, and deliberately so: a provider
 * runs package code on every request, including the overwhelming majority that
 * have nothing to do with that extension. Routes, jobs, hooks and commands only
 * run when something reaches them, and that difference is worth keeping —
 * particularly against a threat model about code edited after install rather
 * than about bad authors.
 *
 * So the declaration is data. A package names classes of its own that should be
 * built once per request instead of on every resolution, and this turns each
 * one into `singleton()`. That covers the case the restriction actually costs —
 * a class that memoises something expensive and is resolved from several call
 * sites within one operation — without executing anything at boot.
 *
 * What it cannot express is an interface bound to an implementation, or an
 * event listener. Both need a provider, and neither has come up; the manifest
 * rejects unknown capability keys, so a sibling declaration can be added later
 * if one does.
 */
class ExtensionBindingRegistrar
{
    public function __construct(
        private Container $container,
        private ExtensionRuntimePlanService $plan,
    ) {
    }

    /**
     * The class a declaration names. The single place that knows the
     * convention; the file validator derives the path the same way.
     */
    public static function classFor(string $extensionId, string $path): string
    {
        return sprintf(
            'Everest\\Extensions\\Packages\\%s\\%s',
            $extensionId,
            str_replace('/', '\\', $path),
        );
    }

    /**
     * Bind every declared class as shared.
     *
     * Registering one is cheap and lazy: `singleton()` records a name, and
     * nothing is autoloaded or constructed until something resolves it. Nothing
     * does unless that package's own routes, jobs, hooks or commands run, and
     * each of those is separately gated on the same plan — so a binding left
     * over from a package that was disabled a moment ago is inert rather than
     * dangerous. That is the same argument the queue limiters beside this one
     * already rely on.
     */
    public function register(): void
    {
        try {
            $entries = $this->plan->withCapability('bindings');
        } catch (\Throwable) {
            // Console commands run before migrations exist; an install that
            // cannot read the plan simply registers no extension bindings.
            return;
        }

        foreach ($entries as $extensionId => $entry) {
            foreach ($entry->capabilities->bindings as $path) {
                // Re-checked here rather than trusted from the projection. This
                // string becomes a class name, and cheap is not a reason to
                // take the one value an attacker with database access could
                // have written on faith.
                if (!preg_match(ExtensionCapabilityVocabulary::BINDING_PATTERN, $path)) {
                    continue;
                }

                $this->container->singleton(self::classFor($extensionId, $path));
            }
        }
    }
}
