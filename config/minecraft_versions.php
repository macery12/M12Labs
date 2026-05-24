<?php

return [
    // Remote provider request timeout in seconds.
    'request_timeout' => (int) env('MINECRAFT_VERSION_REQUEST_TIMEOUT', 8),

    // Fresh cache TTL for version options in seconds.
    'cache_ttl' => (int) env('MINECRAFT_VERSION_CACHE_TTL', 900),

    // Stale cache fallback TTL in seconds.
    'stale_cache_ttl' => (int) env('MINECRAFT_VERSION_STALE_CACHE_TTL', 86400),

    // Upper bound for how many version options are returned per request.
    'max_options' => (int) env('MINECRAFT_VERSION_MAX_OPTIONS', 300),

    'providers' => [
        'mojang_manifest_url' => env('MINECRAFT_MOJANG_MANIFEST_URL', 'https://launchermeta.mojang.com/mc/game/version_manifest.json'),
        'bungee_builds_url' => env('MINECRAFT_BUNGEE_BUILDS_URL', 'https://ci.md-5.net/job/BungeeCord/api/json?tree=builds[number]'),
        'paper_builds_url' => env('MINECRAFT_PAPER_BUILDS_URL', 'https://api.papermc.io/v2/projects/paper/versions/{version}'),
        'forge_promotions_url' => env('MINECRAFT_FORGE_PROMOTIONS_URL', 'https://files.minecraftforge.net/maven/net/minecraftforge/forge/promotions_slim.json'),
        'sponge_metadata_url' => env('MINECRAFT_SPONGE_METADATA_URL', 'https://repo.spongepowered.org/maven/org/spongepowered/spongevanilla/maven-metadata.xml'),
    ],
];
