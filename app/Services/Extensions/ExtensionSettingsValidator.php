<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;

/**
 * Compiles a package's declared settings schema into Laravel rules and applies
 * it to a settings update.
 *
 * `extension_configs.settings` is a plain JSON column that the catalog API
 * returns, and before this it accepted anything at all. Two consequences: a
 * package's own code had to defend against every value an admin could type,
 * and a typed key that matched nothing became silent, permanent configuration
 * nobody would ever see was wrong.
 *
 * Unknown keys are therefore a 422, not a shrug. And a field declared
 * `visibility: secret` is refused outright here — a secret in this column would
 * be readable through the catalog API; those go to ExtensionSecretStore.
 */
class ExtensionSettingsValidator
{
    /**
     * Validate a settings payload against what the package declared.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed> the accepted values, in declared order
     *
     * @throws DisplayException on an unknown key
     * @throws ValidationException on a value that fails its rule
     */
    public function validate(ExtensionCapabilitySet $capabilities, array $settings): array
    {
        $fields = [];
        foreach ($capabilities->settings as $field) {
            $fields[$field->key] = $field;
        }

        // A package that declares no schema keeps the old free-form behaviour;
        // tightening that would break every already-installed package on the
        // upgrade rather than at its next release.
        if ($fields === []) {
            return $settings;
        }

        $unknown = array_diff(array_keys($settings), array_keys($fields));
        if ($unknown !== []) {
            throw new DisplayException(sprintf('Unknown setting(s) for this extension: %s.', implode(', ', $unknown)));
        }

        $rules = [];
        foreach ($fields as $key => $field) {
            if ($field->isSecret()) {
                throw new DisplayException(sprintf('The setting [%s] is declared secret and must be written through the secret store, not the settings API.', $key));
            }

            $rules[$key] = $this->rulesFor($field);
        }

        $validated = Validator::make($settings, $rules)->validate();

        // Rebuild in declared order and fold in defaults for anything absent,
        // so the stored shape does not depend on what the client happened to
        // send.
        $result = [];
        foreach ($fields as $key => $field) {
            if (array_key_exists($key, $validated)) {
                $result[$key] = $validated[$key];
            } elseif ($field->default !== null) {
                $result[$key] = $field->default;
            }
        }

        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    private function rulesFor(SettingDefinition $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];

        $rules[] = match ($field->type) {
            'boolean' => 'boolean',
            'number' => 'numeric',
            'select' => 'string',
            'url' => 'url',
            'host' => 'string',
            default => 'string',
        };

        if (in_array($field->type, ['text', 'textarea', 'select', 'url', 'host'], true)) {
            if ($field->minLength !== null) {
                $rules[] = 'min:' . $field->minLength;
            }
            // An undeclared cap still gets one. Without it a package's settings
            // column is an unbounded write primitive for anybody who can reach
            // the settings API.
            $rules[] = 'max:' . ($field->maxLength ?? 4096);
        }

        if ($field->type === 'number') {
            if ($field->min !== null) {
                $rules[] = 'min:' . $field->min;
            }
            if ($field->max !== null) {
                $rules[] = 'max:' . $field->max;
            }
        }

        if ($field->enum !== null) {
            $rules[] = 'in:' . implode(',', $field->enum);
        }

        if ($field->pattern !== null) {
            // The parser has already bounded the pattern's length and rejected
            // recursive and backreference constructs; this only anchors it.
            $rules[] = 'regex:/^' . str_replace('/', '\\/', $field->pattern) . '$/';
        }

        if ($field->urlHosts !== null && $field->urlHosts !== []) {
            $hosts = $field->urlHosts;
            $rules[] = function (string $attribute, mixed $value, \Closure $fail) use ($hosts): void {
                if ($value === null || $value === '') {
                    return;
                }

                $host = parse_url((string) $value, PHP_URL_HOST) ?: (string) $value;

                foreach ($hosts as $allowed) {
                    if (strcasecmp($host, $allowed) === 0 || str_ends_with(strtolower($host), '.' . strtolower($allowed))) {
                        return;
                    }
                }

                $fail(sprintf('The %s field must point at one of: %s.', $attribute, implode(', ', $hosts)));
            };
        }

        return $rules;
    }
}
