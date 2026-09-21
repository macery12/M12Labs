<?php

namespace Everest\Services\AI;

use Everest\Models\Setting;

/**
 * Every AI configuration read, in one place.
 *
 * Before this, the module read its configuration from two stores directly and
 * in two spellings: `config('modules.ai.agent.max_steps')` and
 * `Setting::get('settings::modules:ai:agent:max_steps')`, with the second
 * overriding the first. Four classes had grown their own private `setting()`
 * helper doing the same string concatenation, and the precedence rule lived
 * nowhere except in the shape of about seventy call sites.
 *
 * The rule is: **an administrator's saved setting wins; the config file is the
 * default.** That is what `config/modules/ai.php` is for -- it supplies the
 * packaged default and the environment variable, and the settings table holds
 * what somebody typed into the admin pages.
 *
 * ## Why this exists as its own class
 *
 * The AI module is being extracted into an extension package, where neither
 * store is available: `config/modules/ai.php` is deleted with the module, and
 * `settings::modules:ai:*` is core's settings table, which a package may not
 * write to. Configuration moves to the package's declared settings, the
 * encrypted secret store, and one package-owned table for the structured
 * values a flat schema cannot express.
 *
 * Routing every read through here first means that swap is a change to this
 * file rather than to seventy call sites in twenty of them -- and, more to the
 * point, it is a change the module's existing tests can still prove, because
 * they are all present and passing on this side of the move.
 *
 * ## Keys
 *
 * One dotted key names a value in both stores: `agent.max_steps` reads
 * `settings::modules:ai:agent:max_steps` and falls back to
 * `config('modules.ai.agent.max_steps')`. Callers never spell either prefix.
 *
 * ## Three things about the two stores that are not obvious
 *
 * These were implicit in the old call sites and are written down here because
 * the extraction has to carry them across, and a fact that lives only in the
 * shape of seventy expressions does not survive being rewritten.
 *
 * 1. **`config()` is not just the file.** `SettingsServiceProvider::boot()`
 *    overlays every allowlisted setting onto config at boot, applying a
 *    string-to-type map on the way (`'true'` becomes `true`, `'1'` becomes
 *    `1`). So for an allowlisted key the two reads below usually agree, and
 *    the settings read is the one that has *not* been type-mapped. That is
 *    why the typed accessors here re-parse rather than cast: `(bool) 'false'`
 *    is `true`, and the raw settings value is where that string comes from.
 *
 * 2. **Secrets read back as a boolean through config.** For a key the secret
 *    encryption service recognises, boot deliberately sets
 *    `config($key, !empty($value))` rather than decrypting at boot. So
 *    `config('modules.ai.key')` is a presence flag and only the settings read
 *    returns the credential. A consequence worth knowing when this moves: an
 *    API key supplied by environment variable and never saved to the table
 *    resolves to the string `'1'`, because the fallback is that flag. The
 *    package's encrypted secret store has no such split and the problem goes
 *    away with it.
 *
 * 3. **Not every key is allowlisted.** The four structured blobs
 *    (`risk_overrides`, `disabled_tools`, `console.safe_commands`,
 *    `privacy.categories`), `agent.calibrated_tool_budgets`, and the
 *    `retention.*` and timeout values live in one store only. The lookup
 *    below is the same either way: an absent setting reads as null and the
 *    config value stands.
 */
final class AiConfiguration
{
    /** The settings-table prefix. Colons, because that is how core keys settings. */
    public const SETTING_PREFIX = 'settings::modules:ai:';

    /** The config-file prefix. Dots, because that is how Laravel keys config. */
    public const CONFIG_PREFIX = 'modules.ai.';

    /**
     * The raw value: the saved setting if there is one, the config default if
     * not, and `$default` if neither store has it.
     *
     * A setting absent from the table reads as null, which is what makes the
     * fallback work. Note that a setting saved as an empty string is *not*
     * absent -- several callers depend on that, because clearing a field in
     * the admin UI is a deliberate act and some of them treat it as "use the
     * packaged default" themselves rather than having it done for them here.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Setting::get(
            self::settingKey($key),
            config(self::CONFIG_PREFIX . $key, $default)
        );
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key);

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    public static function integer(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function number(string $key, float $default = 0.0): float
    {
        $value = self::get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Anything the operator could have meant as true.
     *
     * The settings table stores strings, the config file stores real booleans
     * and an env var supplies "1" or "true", so all three spellings occur for
     * the same switch depending on where it was last set.
     *
     * Only null means absent. An empty string is a *stored* false: saving a
     * switch off through the settings repository writes `''`, so treating that
     * as "never set, use the default" would turn every disabled toggle whose
     * default is true back on. Same rule as `PackageSettings::boolean()`.
     */
    public static function boolean(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * A structured value -- a list or a map.
     *
     * These are the four settings a flat schema cannot express (tool risk
     * overrides, the disabled-tool list, the console command allowlist, the
     * privacy categories). The settings table holds them as JSON text while
     * the config file holds a real array, so both shapes have to be accepted
     * for the same key.
     *
     * Malformed JSON returns the default rather than throwing. A parse error
     * here would take down whichever surface happened to read it first, and a
     * single unparseable row is not a reason to refuse to answer a question
     * about something else.
     *
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }

    /**
     * A credential.
     *
     * Separate from {@see string()} because of the trap described above: for a
     * key the secret encryption service recognises, boot sets the config entry
     * to `!empty($value)` rather than the value, so the fallback behind a
     * missing setting is the boolean `true`, not a credential. Casting that to
     * a string produces `'1'`, which is then sent to the provider as an API
     * key and rejected — an install configured only by environment variable
     * fails with an authentication error rather than "no key configured".
     *
     * So a boolean here means "the settings table has nothing", and that is
     * what it reports.
     */
    public static function secret(string $key, string $default = ''): string
    {
        $value = self::get($key);

        if (is_bool($value)) {
            return $default;
        }

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    /**
     * Save an administrator's value.
     *
     * Writes the settings table only. The config file is the packaged default
     * and is never written at runtime -- which is also why clearing a value
     * here does not restore the default: an empty string is a stored value,
     * and the callers that want the default back on a blank field say so
     * themselves.
     */
    public static function set(string $key, mixed $value): void
    {
        Setting::set(self::settingKey($key), $value);
    }

    /** The settings-table key for a dotted key. */
    public static function settingKey(string $key): string
    {
        return self::SETTING_PREFIX . str_replace('.', ':', $key);
    }

    /** The config-file key for a dotted key. */
    public static function configKey(string $key): string
    {
        return self::CONFIG_PREFIX . $key;
    }
}
