<?php

return [
    /*
     * Enable or disable the extensions module.
     * When enabled, admins can configure server extensions like player managers.
     */
    'enabled' => env('EXTENSIONS_ENABLED', false),

    /*
     * Available extensions configuration.
     * Each extension can be enabled/disabled independently.
     */
    'available' => [
        'minecraft_player_manager' => [
            'name' => 'Minecraft Player Manager',
            'description' => 'Manage Minecraft Java Edition players directly from the panel. Includes whitelist management, banning, kicking, operator controls, and more.',
            'version' => '1.0.0',
            'author' => 'Bimbab189',
            'icon' => 'users',
            'enabled' => env('EXTENSION_MINECRAFT_PLAYER_MANAGER_ENABLED', false),
            /*
             * Default nests and eggs this extension works with.
             * These can be overridden in the admin panel.
             * Format: nest_id => [egg_ids] or nest_id => [] for all eggs in nest
             */
            'allowed_nests' => [],
            'allowed_eggs' => [],
        ],
    ],

    /*
     * Extension permissions prefix.
     * All extension permissions will be prefixed with this.
     */
    'permission_prefix' => 'extension',
];
