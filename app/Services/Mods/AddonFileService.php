<?php

namespace Everest\Services\Mods;

class AddonFileService
{
    /**
     * Supported disabled suffixes, including the intentionally misspelled ".dissabled" variant for compatibility with existing servers.
     */
    private const DISABLED_SUFFIXES = ['.jar.disabled', '.disabled', '.disabled.jar', '.dissabled', '.dissabled.jar'];

    public static function isJarLike(string $name): bool
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '.jar')) {
            return true;
        }

        foreach (self::DISABLED_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                $trimmed = substr($lower, 0, -strlen($suffix));

                return str_ends_with($trimmed, '.jar') || !str_contains($trimmed, '.');
            }
        }

        return false;
    }

    public static function stripDisabledSuffix(string $name): string
    {
        $lower = strtolower($name);
        foreach (self::DISABLED_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return $name;
    }

    public static function isDisabledFile(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::DISABLED_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                $trimmed = substr($lower, 0, -strlen($suffix));

                return str_ends_with($trimmed, '.jar') || !str_contains($trimmed, '.');
            }
        }

        return false;
    }
}
