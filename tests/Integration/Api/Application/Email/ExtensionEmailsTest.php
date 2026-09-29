<?php

namespace Everest\Tests\Integration\Api\Application\Email;

use Everest\Models\Setting;
use Everest\Mail\ExtensionMail;
use Everest\Models\EmailDelivery;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\ExtensionMailLimiter;
use Everest\Services\Email\ExtensionEmailCleanup;
use Everest\Repositories\Eloquent\SettingsRepository;
use Everest\Services\Email\Templating\TwigEnvironmentFactory;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\EmailDefinition;
use Everest\Services\Extensions\Manifest\Definitions\EmailVariableDefinition;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * Admin → Email → Extension emails.
 *
 * The package's template is read from a temporary app path. Saved overrides
 * have to go where the renderer looks for them, inside the real email tree,
 * so every test removes what it wrote there -- a directory left behind owned
 * by whoever ran the suite would stop the panel saving overrides of its own.
 */
class ExtensionEmailsTest extends ApplicationApiIntegrationTestCase
{
    private const ID = 'demo_mail';

    private const TEMPLATE = '{% extends "layout.twig" %}{% block content %}<p>Hello {{ userName }}, {{ ticketTitle }} has a reply.</p>{% endblock %}';

    private string $tmp;

    private bool $extensionsDirExisted;

    public function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/extension-emails-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp . '/app/Extensions/Packages/' . self::ID . '/emails');
        File::put($this->tmp . '/app/Extensions/Packages/' . self::ID . '/emails/ticket-reply.twig', self::TEMPLATE);
        $this->app->useAppPath($this->tmp . '/app');
        // Compiled templates would otherwise land in the live storage tree.
        $this->app->useStoragePath($this->tmp . '/storage');

        $this->extensionsDirExisted = is_dir($this->overridesRoot());

        $email = new EmailDefinition(
            type: 'ticket-reply',
            labelKey: 'ext.demo_mail.emails.reply',
            descriptionKey: null,
            subject: 'New reply on {{ ticketTitle }}',
            variables: [new EmailVariableDefinition('ticketTitle', 'The ticket', 'Server down', true)],
        );

        ExtensionPackage::query()->create([
            'extension_id' => self::ID,
            'package_id' => self::ID,
            'name' => 'Demo Mail',
            'installed_version' => '1.0.0',
            'manifest' => [],
            'manifest_version' => 3,
            'signature_state' => 'verified',
            'state' => 'installed_disabled',
            'capabilities' => json_decode(json_encode(new ExtensionCapabilitySet(emails: [$email])), true),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->overridesRoot() . '/' . self::ID);
        if (!$this->extensionsDirExisted) {
            @rmdir($this->overridesRoot());
        }
        File::deleteDirectory($this->tmp);
        app(ExtensionMailLimiter::class)->forget(self::ID);
        SettingsRepository::flushCache();

        parent::tearDown();
    }

    public function testInstalledExtensionsAreListedWithTheirTypes(): void
    {
        $this->getJson('/api/application/email/extensions')
            ->assertOk()
            ->assertJsonPath('default_hourly_limit', 100)
            ->assertJsonPath('extensions.0.id', self::ID)
            ->assertJsonPath('extensions.0.active', false)
            ->assertJsonPath('extensions.0.hourly_limit', 100)
            ->assertJsonPath('extensions.0.types.0.key', 'ext:demo_mail:ticket-reply')
            ->assertJsonPath('extensions.0.types.0.subject', 'New reply on {{ ticketTitle }}')
            ->assertJsonPath('extensions.0.types.0.enabled', true)
            ->assertJsonPath('extensions.0.types.0.is_customized', false)
            ->assertJsonPath('extensions.0.types.0.variables.0.name', 'userName')
            ->assertJsonPath('extensions.0.types.0.variables.2.name', 'ticketTitle');
    }

    public function testSwitchingATypeOffCreatesItsRow(): void
    {
        $this->putJson('/api/application/email/extensions/demo_mail/ticket-reply', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertFalse(EmailNotificationSetting::isTemplateEnabled('ext:demo_mail:ticket-reply'));
        $this->getJson('/api/application/email/extensions')->assertJsonPath('extensions.0.types.0.enabled', false);
        // And the built-in list does not grow an extension row.
        $this->getJson('/api/application/email/notifications')->assertJsonMissingPath('categories.extension');
    }

    public function testTheHourlyLimitIsSaved(): void
    {
        $this->putJson('/api/application/email/extensions/demo_mail', ['hourly_limit' => 25])
            ->assertOk()
            ->assertJsonPath('hourly_limit', 25);

        $this->assertSame(25, app(ExtensionMailLimiter::class)->limitFor(self::ID));
    }

    public function testAZeroLimitIsRefused(): void
    {
        $this->putJson('/api/application/email/extensions/demo_mail', ['hourly_limit' => 0])->assertStatus(422);
    }

    public function testAnUndeclaredTypeIsNotFound(): void
    {
        $this->getJson('/api/application/email/extensions/demo_mail/welcome/source')->assertNotFound();
    }

    public function testTheSourceIsThePackagesTemplateUntilEdited(): void
    {
        $this->getJson('/api/application/email/extensions/demo_mail/ticket-reply/source')
            ->assertOk()
            ->assertJsonPath('content', self::TEMPLATE)
            ->assertJsonPath('is_customized', false);
    }

    public function testAnOverrideIsSavedPreviewedAndReverted(): void
    {
        $edited = str_replace('has a reply', 'was answered', self::TEMPLATE);

        $this->putJson('/api/application/email/extensions/demo_mail/ticket-reply/source', ['content' => $edited])
            ->assertOk()
            ->assertJsonPath('is_customized', true);

        $this->assertFileExists($this->overridesRoot() . '/demo_mail/ticket-reply.twig.custom');
        $this->getJson('/api/application/email/extensions/demo_mail/ticket-reply/source')->assertJsonPath('content', $edited);
        $this->assertStringContainsString('Server down was answered', $this->get('/api/application/email/extensions/demo_mail/ticket-reply/preview')->assertOk()->getContent());

        $this->deleteJson('/api/application/email/extensions/demo_mail/ticket-reply/source')->assertOk()->assertJsonPath('is_customized', false);
        $this->assertFileDoesNotExist($this->overridesRoot() . '/demo_mail/ticket-reply.twig.custom');
    }

    /** Same sandbox as a built-in template, checked before anything is saved. */
    public function testAnOverrideReachingOutsideTheSandboxIsRefused(): void
    {
        $this->putJson('/api/application/email/extensions/demo_mail/ticket-reply/source', ['content' => '{{ include("/etc/passwd") }}'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'InvalidEmailTemplate');

        $this->assertFileDoesNotExist($this->overridesRoot() . '/demo_mail/ticket-reply.twig.custom');
    }

    /** What the worker renders: the package's file inside the panel's layout. */
    public function testAQueuedMessageRendersThePackagesTemplate(): void
    {
        $html = (new ExtensionMail(self::ID, 'ticket-reply', 'New reply on Server down', [
            'ticketTitle' => 'Server down',
            'userName' => 'jane',
            'userEmail' => 'jane@m12labs.test-suite.net',
        ]))->renderBody();

        $this->assertStringContainsString('Hello jane, Server down has a reply.', $html);
        $this->assertStringContainsString('<html', $html, 'The panel layout wraps it.');
    }

    /** `_` is a LIKE wildcard; `demo_mail` must not also match `demoxmail`. */
    public function testTheLogFiltersByExtension(): void
    {
        foreach (['ext:demo_mail:ticket-reply', 'ext:demoxmail:ticket-reply', 'auth.2fa_enabled'] as $key) {
            EmailDelivery::query()->create([
                'template_key' => $key,
                'recipient' => 'a@m12labs.test-suite.net',
                'subject' => 'x',
                'status' => EmailDelivery::STATUS_SENT,
            ]);
        }

        $this->getJson('/api/application/email/logs?extension=demo_mail')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.template_key', 'ext:demo_mail:ticket-reply');
    }

    /** What uninstalling with the data dropped removes; the log stays. */
    public function testDroppingAnExtensionsDataForgetsItsEmailSettings(): void
    {
        $this->putJson('/api/application/email/extensions/demo_mail/ticket-reply/source', ['content' => self::TEMPLATE])->assertOk();
        EmailNotificationSetting::query()->create(['template_key' => 'ext:demo_mail:ticket-reply', 'enabled' => false, 'category' => 'extension', 'name' => 'x']);
        EmailNotificationSetting::query()->create(['template_key' => 'ext:demoxmail:ticket-reply', 'enabled' => false, 'category' => 'extension', 'name' => 'y']);
        app(ExtensionMailLimiter::class)->setLimit(self::ID, 5);

        app(ExtensionEmailCleanup::class)->forget(self::ID);
        SettingsRepository::flushCache();

        $this->assertFileDoesNotExist($this->overridesRoot() . '/demo_mail/ticket-reply.twig.custom');
        $this->assertDirectoryDoesNotExist($this->overridesRoot() . '/demo_mail');
        $this->assertNull(EmailNotificationSetting::query()->where('template_key', 'ext:demo_mail:ticket-reply')->first());
        $this->assertNotNull(EmailNotificationSetting::query()->where('template_key', 'ext:demoxmail:ticket-reply')->first());
        $this->assertNull(Setting::get('settings::modules:email:extension_limit:demo_mail'));
    }

    private function overridesRoot(): string
    {
        return resource_path(TwigEnvironmentFactory::TEMPLATE_ROOT) . '/extensions';
    }
}
