<?php

namespace Everest\Extensions\Sdk\Http;

/**
 * What an internally dispatched request came back with.
 *
 * A decoded value rather than the framework's response object, deliberately:
 * the object has `send()` on it, and sending a response from inside a package
 * writes into whatever the caller has open. There is nothing here to send.
 */
final readonly class InternalResponse
{
    /**
     * @param array<mixed>|null $json decoded body, or null when it was not JSON
     */
    public function __construct(
        public int $status,
        public ?array $json,
        public string $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    /**
     * A value from the decoded body by dot path, or the default.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return data_get($this->json, $key, $default);
    }

    /**
     * The panel's error detail, when the call failed and said why.
     *
     * Only the detail, never the envelope: under APP_DEBUG the panel injects
     * `source.file`, `source.line` and a full `meta.trace`, none of which a
     * package should be putting in front of anyone.
     */
    public function errorDetail(): ?string
    {
        $detail = data_get($this->json, 'errors.0.detail');

        return is_string($detail) && $detail !== '' ? $detail : null;
    }
}
