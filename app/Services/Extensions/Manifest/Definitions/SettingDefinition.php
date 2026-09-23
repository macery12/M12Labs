<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One validated configuration field.
 *
 * The schema is enforced server-side on both manifest ingestion and every
 * settings update, and unknown keys are rejected, so a typo cannot silently
 * become configuration. There is no `password` type: secrets belong in the
 * encrypted store, not in the JSON settings column the catalog API returns.
 *
 * `visibleWhen` is presentation only: a hidden field is still stored and
 * validated, so switching a provider away and back loses nothing.
 */
final readonly class SettingDefinition implements \JsonSerializable
{
    public function __construct(
        public string $key,
        public string $type,
        public string $labelKey,
        public ?string $helpKey = null,
        public bool $required = false,
        public mixed $default = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        /** @var array<int, string>|null */
        public ?array $enum = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        /** @var array<int, string>|null Permitted hosts for url/host fields. */
        public ?array $urlHosts = null,
        public string $visibility = 'admin',
        /** Whether changing this value requires a panel rebuild to take effect. */
        public bool $requiresRebuild = false,
        /** When the generated form shows this field; null means always. */
        public ?VisibilityCondition $visibleWhen = null,
    ) {
    }

    public function isSecret(): bool
    {
        return $this->visibility === 'secret';
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /**
     * Stored and validated like any admin setting, but never rendered by the
     * panel's generated form: a value the package's own settings UI owns, or a
     * legacy key kept so installs that still carry it continue to validate.
     */
    public function isInternal(): bool
    {
        return $this->visibility === 'internal';
    }

    public function withVisibleWhen(?VisibilityCondition $condition): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->labelKey,
            $this->helpKey,
            $this->required,
            $this->default,
            $this->minLength,
            $this->maxLength,
            $this->pattern,
            $this->enum,
            $this->min,
            $this->max,
            $this->urlHosts,
            $this->visibility,
            $this->requiresRebuild,
            $condition,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type,
            'labelKey' => $this->labelKey,
            'helpKey' => $this->helpKey,
            'required' => $this->required,
            'default' => $this->default,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'enum' => $this->enum,
            'min' => $this->min,
            'max' => $this->max,
            'urlHosts' => $this->urlHosts,
            'visibility' => $this->visibility,
            'requiresRebuild' => $this->requiresRebuild,
            // Emitted only when declared, so a package without conditions
            // keeps a byte-identical capability hash.
            'visibleWhen' => $this->visibleWhen?->jsonSerialize(),
        ], fn ($value) => $value !== null && $value !== false);
    }
}
