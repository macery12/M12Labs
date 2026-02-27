<?php

return [
    /*
     * Enable or disable the mods module.
     */
    'enabled' => env('MODS_ENABLED', false),

    /*
     * Default mod source (modrinth or curseforge).
     * Modrinth is the default as it doesn't require an API key.
     */
    'default_source' => env('MODS_DEFAULT_SOURCE', 'modrinth'),

    /*
     * Set the API key for CurseForge API.
     */
    'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

    /*
     * Rate limiting for CurseForge API requests
     * CurseForge allows approximately 2000 requests per hour
     */
    'rate_limit' => [
        'requests_per_minute' => env('MODS_RATE_LIMIT_PER_MINUTE', 30),
        'requests_per_hour' => env('MODS_RATE_LIMIT_PER_HOUR', 1800),
    ],

    /*
     * Cache settings for CurseForge API responses
     */
    'cache' => [
        'enabled' => env('MODS_CACHE_ENABLED', true),
        'ttl' => [
            'search' => env('MODS_CACHE_SEARCH_TTL', 300), // 5 minutes
            'mod_details' => env('MODS_CACHE_MOD_DETAILS_TTL', 1800), // 30 minutes
            'mod_files' => env('MODS_CACHE_MOD_FILES_TTL', 600), // 10 minutes
            'versions' => env('MODS_CACHE_VERSIONS_TTL', 3600), // 1 hour
            'loaders' => env('MODS_CACHE_LOADERS_TTL', 3600), // 1 hour
        ],
    ],

    /*
     * CurseForge API endpoint
     */
    'curseforge_api_url' => env('CURSEFORGE_API_URL', 'https://api.curseforge.com/v1'),

    /*
     * Modrinth API endpoint
     */
    'modrinth_api_url' => env('MODRINTH_API_URL', 'https://api.modrinth.com/v2'),

    /*
     * Spigot (Spiget API) endpoint and toggle.
     */
    'spiget_api_url' => env('SPIGET_API_URL', 'https://api.spiget.org/v2'),
    'spiget_enabled' => env('SPIGET_ENABLED', false),

    /*
     * Maximum download sizes (in bytes).
     */
    'max_mod_size' => env('MODS_MAX_MOD_SIZE', 157286400), // 150MB default
    'max_plugin_size' => env('PLUGINS_MAX_SIZE', 104857600), // 100MB default
    'max_modpack_size' => env('MODPACK_MAX_SIZE', 524288000), // 500MB default

    /*
     * Default page size for mod listings
     */
    'default_page_size' => env('MODS_DEFAULT_PAGE_SIZE', 20),

    /*
     * Maximum page size for mod listings
     */
    'max_page_size' => env('MODS_MAX_PAGE_SIZE', 50),
];
