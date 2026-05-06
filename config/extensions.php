<?php

return [
    'scan' => [
        'phpcs_binary'    => env('EXTENSIONS_PHPCS_BIN', 'phpcs'),
        'eslint_binary'   => env('EXTENSIONS_ESLINT_BIN', 'npx eslint'),
        'semgrep_binary'  => env('EXTENSIONS_SEMGREP_BIN', 'semgrep'),
        'semgrep_enabled' => env('EXTENSIONS_SEMGREP_ENABLED', false),
        'block_on_high'   => env('EXTENSIONS_BLOCK_ON_HIGH', true),
        'temp_dir'        => storage_path('app/extension-scans'),
        'install_dir'     => storage_path('app/extensions/installed'),
    ],
];
