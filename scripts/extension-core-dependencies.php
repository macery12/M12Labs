#!/usr/bin/env php
<?php

/*
 * Regenerate resources/extensions/core-dependencies.json.
 *
 * The panel's own direct dependencies, as this repository declares them. An
 * installed extension's requirements are checked against frontend/package.json
 * and composer.json, and an operator adds anything missing to those same files
 * — so once they have, the files can no longer say which packages core needs
 * and which only an extension did. This snapshot, taken from the repository
 * before any operator touches it, is what lets the panel suggest removing a
 * package an uninstalled extension required without ever suggesting one core
 * itself imports.
 *
 * Run after changing core's dependencies and commit the result;
 * CoreDependencyBaselineTest fails until you do.
 *
 * Usage: php scripts/extension-core-dependencies.php [--check]
 */

$root = dirname(__DIR__);
$target = $root . '/resources/extensions/core-dependencies.json';

$package = json_decode((string) file_get_contents($root . '/frontend/package.json'), true, 512, JSON_THROW_ON_ERROR);
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$npm = array_keys((array) ($package['dependencies'] ?? []));
$php = array_values(array_filter(
    array_map('strtolower', array_keys((array) ($composer['require'] ?? []))),
    fn (string $name): bool => str_contains($name, '/'),
));

sort($npm, SORT_STRING);
sort($php, SORT_STRING);

$json = json_encode(['npm' => $npm, 'composer' => $php], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

if (in_array('--check', $argv, true)) {
    if (!is_file($target) || file_get_contents($target) !== $json) {
        fwrite(STDERR, "resources/extensions/core-dependencies.json is out of date. Run: php scripts/extension-core-dependencies.php\n");
        exit(1);
    }

    exit(0);
}

file_put_contents($target, $json);
echo "Wrote resources/extensions/core-dependencies.json\n";
