<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Illuminate\Validation\ValidationException;
use Everest\Services\Extensions\ExtensionSettingsValidator;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;

/**
 * Server-side validation of an extension's settings.
 *
 * Before this, extension_configs.settings accepted anything: a mistyped key
 * became silent permanent configuration nobody would ever see was wrong, and
 * every package had to defend against every value an admin could type.
 */
class ExtensionSettingsValidatorTest extends TestCase
{
    private function validator(): ExtensionSettingsValidator
    {
        return new ExtensionSettingsValidator();
    }

    private function schema(SettingDefinition ...$fields): ExtensionCapabilitySet
    {
        return new ExtensionCapabilitySet(settings: $fields);
    }

    public function testUnknownKeysAreRejected(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Unknown setting(s) for this extension: zone_id_typo');

        $this->validator()->validate(
            $this->schema(new SettingDefinition(key: 'zone_id', type: 'text', labelKey: 'ext.demo.zone')),
            ['zone_id_typo' => 'abc']
        );
    }

    /**
     * A package that declares no schema keeps the old behaviour. Tightening it
     * would break every already-installed package on the upgrade rather than at
     * its next release.
     */
    public function testAPackageWithoutASchemaIsUnaffected(): void
    {
        $settings = ['anything' => 'goes'];

        $this->assertSame($settings, $this->validator()->validate($this->schema(), $settings));
    }

    public function testEnumValuesAreEnforced(): void
    {
        $schema = $this->schema(new SettingDefinition(
            key: 'mode',
            type: 'select',
            labelKey: 'ext.demo.mode',
            enum: ['proxied', 'direct'],
        ));

        $this->assertSame(['mode' => 'proxied'], $this->validator()->validate($schema, ['mode' => 'proxied']));

        $this->expectException(ValidationException::class);
        $this->validator()->validate($schema, ['mode' => 'something-else']);
    }

    public function testNumericBoundsAreEnforced(): void
    {
        $schema = $this->schema(new SettingDefinition(
            key: 'ttl',
            type: 'number',
            labelKey: 'ext.demo.ttl',
            min: 60,
            max: 86400,
        ));

        $this->assertSame(['ttl' => 300], $this->validator()->validate($schema, ['ttl' => 300]));

        $this->expectException(ValidationException::class);
        $this->validator()->validate($schema, ['ttl' => 5]);
    }

    /**
     * A text field with no declared cap still gets one. Without it the settings
     * column is an unbounded write for anybody who can reach the endpoint.
     */
    public function testTextFieldsAreLengthCappedEvenWhenUndeclared(): void
    {
        $schema = $this->schema(new SettingDefinition(key: 'note', type: 'text', labelKey: 'ext.demo.note'));

        $this->expectException(ValidationException::class);
        $this->validator()->validate($schema, ['note' => str_repeat('a', 5000)]);
    }

    public function testUrlHostsAreEnforced(): void
    {
        $schema = $this->schema(new SettingDefinition(
            key: 'endpoint',
            type: 'url',
            labelKey: 'ext.demo.endpoint',
            urlHosts: ['api.cloudflare.com'],
        ));

        $this->assertSame(
            ['endpoint' => 'https://api.cloudflare.com/client/v4'],
            $this->validator()->validate($schema, ['endpoint' => 'https://api.cloudflare.com/client/v4'])
        );

        $this->expectException(ValidationException::class);
        $this->validator()->validate($schema, ['endpoint' => 'https://attacker.example/client/v4']);
    }

    /**
     * A secret in this column would be readable through the catalog API, so a
     * field declared secret is refused here rather than merely discouraged.
     */
    public function testSecretVisibilityIsRefusedOnTheSettingsPath(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must be written through the secret store');

        $this->validator()->validate(
            $this->schema(new SettingDefinition(
                key: 'token',
                type: 'text',
                labelKey: 'ext.demo.token',
                visibility: 'secret',
            )),
            ['token' => 'abc']
        );
    }

    /** Absent fields fall back to declared defaults, in declared order. */
    public function testDefaultsFillInForAbsentFields(): void
    {
        $schema = $this->schema(
            new SettingDefinition(key: 'ttl', type: 'number', labelKey: 'ext.demo.ttl', default: 300),
            new SettingDefinition(key: 'mode', type: 'select', labelKey: 'ext.demo.mode', default: 'proxied', enum: ['proxied', 'direct']),
        );

        $this->assertSame(
            ['ttl' => 60, 'mode' => 'proxied'],
            $this->validator()->validate($schema, ['ttl' => 60])
        );
    }
}
