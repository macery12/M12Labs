<?php

use Illuminate\Support\Str;

if (!function_exists('is_digit')) {
    /**
     * Deal with normal (and irritating) PHP behavior to determine if
     * a value is a non-float positive integer.
     */
    function is_digit(mixed $value): bool
    {
        return !is_bool($value) && ctype_digit(strval($value));
    }
}

if (!function_exists('object_get_strict')) {
    /**
     * Get an object using dot notation. An object key with a value of null is still considered valid
     * and will not trigger the response of a default value (unlike object_get).
     */
    function object_get_strict(object $object, ?string $key, $default = null): mixed
    {
        if (is_null($key) || trim($key) == '') {
            return $object;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_object($object) || !property_exists($object, $segment)) {
                return value($default);
            }

            $object = $object->{$segment};
        }

        return $object;
    }
}

if (!function_exists('is_test_domain')) {
    /**
     * Determine if the given email belongs to a configured test domain.
     */
    function is_test_domain(string $email): bool
    {
        if (!str_contains($email, '@')) {
            return false;
        }

        $domain = Str::lower(Str::afterLast($email, '@'));
        $testDomains = array_map('strtolower', config('email.test_domains', []));

        return in_array($domain, $testDomains, true);
    }
}

if (!function_exists('isTestDomain')) {
    /**
     * CamelCase alias provided to satisfy consumers expecting this naming style.
     */
    function isTestDomain(string $email): bool
    {
        return is_test_domain($email);
    }
}

if (!function_exists('is_valid_email_syntax')) {
    /**
     * Check if the given email has a valid format without DNS lookups.
     */
    function is_valid_email_syntax(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
