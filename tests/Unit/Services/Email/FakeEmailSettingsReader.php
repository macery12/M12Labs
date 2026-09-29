<?php

namespace Everest\Tests\Unit\Services\Email;

use Everest\Services\Email\EmailSettingsReader;

/**
 * Settings from an array. Setting::set() needs a database and leaks across
 * unit tests, so these use this instead.
 */
class FakeEmailSettingsReader extends EmailSettingsReader
{
    /**
     * @param array<string, string> $values keyed without the "settings::modules:email:" prefix
     */
    public function __construct(public array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[str_replace('settings::modules:email:', '', $key)] ?? $default;
    }
}
