<?php

return [
    /*
     * Enable or disable the mods module.
     */
    'enabled' => env('MODS_ENABLED', false),

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
     * CurseForge API endpoint
     */
    'curseforge_api_url' => env('CURSEFORGE_API_URL', 'https://api.curseforge.com/v1'),

    /*
     * Default page size for mod listings
     */
    'default_page_size' => env('MODS_DEFAULT_PAGE_SIZE', 20),

    /*
     * Maximum page size for mod listings
     */
    'max_page_size' => env('MODS_MAX_PAGE_SIZE', 50),
];
