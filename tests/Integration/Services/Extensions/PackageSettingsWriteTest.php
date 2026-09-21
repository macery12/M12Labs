<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Extensions\Sdk\DisplayException;
use Illuminate\Validation\ValidationException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Extensions\Sdk\Services\PackageSettings;
use Everest\Services\Extensions\ExtensionRuntimeEntry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;

/**
 * A package writing its own declared settings.
 *
 * The store was read-only, which is fine for a package whose whole
 * configuration fits the panel's generated settings form. It is not fine for
 * one with a settings UI of its own -- several pages, each owning a few keys --
 * because such a package could read its configuration and never save it.
 *
 * The write goes through the same validator an administrator's edit does, so
 * the interesting question is not whether it stores a value but what it
 * refuses: an undeclared key, an out-of-range value, a secret, and a package
 * that is not currently loadable.
 */
class PackageSettingsWriteTest extends IntegrationTestCase
{
    private const ID = 'sdk_settings_write';

    /** @param array<int, SettingDefinition> $fields */
    private function plan(array $fields, string $id = self::ID): void
    {
        $plan = $this->createMock(ExtensionRuntimePlanService::class);
        $plan->method('entry')->willReturnCallback(
            fn (string $requested) => $requested === $id
                ? new ExtensionRuntimeEntry($id, '1.0.0', new ExtensionCapabilitySet(settings: $fields))
                : null
        );

        $this->app->instance(ExtensionRuntimePlanService::class, $plan);
    }

    /** @return array<int, SettingDefinition> */
    private function fields(): array
    {
        return [
            new SettingDefinition('provider', 'select', 'ext.assistant.provider', enum: ['ollama', 'openai'], default: 'ollama'),
            new SettingDefinition('agent_enabled', 'boolean', 'ext.assistant.agent', default: false),
            new SettingDefinition('max_steps', 'number', 'ext.assistant.steps', default: 12, min: 1, max: 40),
        ];
    }

    /**
     * Idempotent, and it rewrites the settings every time. The integration
     * suite shares one database across a run with no per-test rollback, so a
     * fixture that only inserts collides with itself on the second test.
     */
    private function config(array $settings = []): void
    {
        ExtensionConfig::updateOrCreateConfig(self::ID, [
            'enabled' => true,
            'settings' => $settings,
        ]);
    }

    /**
     * The property the whole design rests on.
     *
     * The validator fills absent keys from their declared defaults, so handing
     * it a partial payload on its own would quietly reset every key the caller
     * did not mention. A nine-page settings section saving one page would wipe
     * the other eight. So a save merges over what is stored first.
     */
    public function testAPartialSaveMergesRatherThanResettingOmittedKeys(): void
    {
        $this->plan($this->fields());
        $this->config(['provider' => 'openai', 'agent_enabled' => true, 'max_steps' => 30]);

        PackageSettings::for(self::ID)->save(['max_steps' => 8]);

        $saved = PackageSettings::for(self::ID);

        $this->assertSame(8, $saved->integer('max_steps'));
        $this->assertSame('openai', $saved->string('provider'));
        $this->assertTrue($saved->boolean('agent_enabled'));
    }

    public function testTheInstanceReflectsWhatItJustSaved(): void
    {
        $this->plan($this->fields());
        $this->config(['provider' => 'ollama']);

        $settings = PackageSettings::for(self::ID);
        $settings->save(['provider' => 'openai']);

        $this->assertSame('openai', $settings->string('provider'));
    }

    public function testAnUndeclaredKeyIsRefused(): void
    {
        $this->plan($this->fields());
        $this->config();

        $this->expectException(\Everest\Exceptions\DisplayException::class);
        $this->expectExceptionMessage('Unknown setting(s) for this extension: smuggled');

        PackageSettings::for(self::ID)->save(['smuggled' => 'value']);
    }

    public function testAValueOutsideItsDeclaredRangeIsRefused(): void
    {
        $this->plan($this->fields());
        $this->config();

        $this->expectException(ValidationException::class);

        PackageSettings::for(self::ID)->save(['max_steps' => 400]);
    }

    /**
     * extension_configs.settings is returned by the catalog API, so a field
     * declared secret must not be reachable through this path either -- it
     * would be a way to move a credential out of the encrypted store and into
     * a column the API hands back.
     */
    public function testASecretFieldCannotBeWrittenThroughSettings(): void
    {
        $this->plan([new SettingDefinition('api_key', 'text', 'ext.assistant.key', visibility: 'secret')]);
        $this->config();

        $this->expectException(\Everest\Exceptions\DisplayException::class);
        $this->expectExceptionMessage('declared secret');

        PackageSettings::for(self::ID)->save(['api_key' => 'sk-live-000']);
    }

    /**
     * Absent from the runtime plan means disabled, quarantined, tampered or
     * incompatible. The validator is reached through the plan entry, so
     * proceeding would be the one write path that skips the schema entirely.
     */
    public function testAPackageOutsideTheRuntimePlanCannotSave(): void
    {
        $this->plan($this->fields(), id: 'something_else');
        $this->config();

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('not currently loadable');

        PackageSettings::for(self::ID)->save(['provider' => 'openai']);
    }

    /** A package with no declared schema keeps the old free-form behaviour. */
    public function testAPackageWithoutASchemaStillSaves(): void
    {
        $this->plan([]);
        $this->config(['anything' => 'goes']);

        PackageSettings::for(self::ID)->save(['anything' => 'else']);

        $this->assertSame('else', PackageSettings::for(self::ID)->string('anything'));
    }
}
