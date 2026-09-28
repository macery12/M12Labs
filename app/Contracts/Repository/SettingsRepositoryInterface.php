<?php

namespace Everest\Contracts\Repository;

interface SettingsRepositoryInterface extends RepositoryInterface
{
    /**
     * Store a new persistent setting in the database.
     *
     * @throws \Everest\Exceptions\Model\DataValidationException
     * @throws \Everest\Exceptions\Repository\RecordNotFoundException
     */
    public function set(string $key, ?string $value = null);

    /**
     * Retrieve a persistent setting from the database.
     */
    public function get(string $key, mixed $default): mixed;

    /**
     * Load every setting and prime the cache, returning the stored values
     * with secrets still encrypted.
     *
     * @return array<string, mixed>
     */
    public function loadAll(): array;

    /**
     * Remove a key from the database cache.
     */
    public function forget(string $key);
}
