<?php

/*
|--------------------------------------------------------------------------
| Extensions module
|--------------------------------------------------------------------------
|
| The user-facing module toggle, plus the legacy core-extension declarations.
| Operator safety and lifecycle policy for the extension platform itself
| (archive limits, build limits, signing, queue drain) lives in
| config/extensions.php instead.
|
| Installed packages are NOT declared here. A package ships a manifest v3,
| which is the only place its capabilities may be declared, and the installer
| projects that manifest into `extension_packages.capabilities`. See
| docs/extension_update.md.
|
*/

return [
    /*
     * Enable or disable the extensions module.
     * When enabled, admins can configure server extensions like player managers.
     */
    'enabled' => env('EXTENSIONS_ENABLED', true),

    /*
     * Legacy core-extension declarations. Kept for installations that added
     * their own entries before packages existed.
     *
     * These are not packages: they ship no manifest, so they declare no
     * capabilities, contribute no permissions, hooks, queues or pages, and are
     * never signed. ExtensionRuntimePlanService keeps them in the enabled set
     * so their access middleware behaves as it always did, and
     * ExtensionCatalogService lists them with `status: 'core'`.
     *
     * Do not add to this list. Ship a manifest v3 package instead — anything
     * declared here is invisible to every capability check the platform makes.
     *
     * Recognised keys per entry: name, description, version, author, icon,
     * route, enabled, allowed_nests, allowed_eggs, settings_schema. Values are
     * read straight from config; unlike a package manifest, nothing here is
     * validated, so a malformed entry is a configuration bug rather than a
     * rejected install.
     */
    'available' => [

    ],
];
