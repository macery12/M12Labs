<?php

namespace Everest\Tests\Integration\Services\Email;

use Everest\Models\User;
use Everest\Models\Setting;
use Everest\Mail\ExtensionMail;
use Everest\Models\EmailDelivery;
use Illuminate\Support\Facades\Queue;
use Everest\Jobs\Email\SendPanelMailJob;
use Everest\Models\EmailNotificationSetting;
use Everest\Extensions\Sdk\Services\PackageMail;
use Everest\Services\Email\ExtensionMailLimiter;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Repositories\Eloquent\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\Definitions\EmailDefinition;
use Everest\Services\Extensions\Manifest\Definitions\EmailVariableDefinition;

/**
 * An extension sending email through the SDK.
 *
 * It has to land in the same pipeline as the panel's own mail -- the switch,
 * the delivery log, the queue -- under a key that names the extension, and it
 * has to refuse anything the manifest did not declare before it gets there.
 */
class PackageMailTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const ID = 'sdk_mail';

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('settings::modules:email:enabled', 'true');
        app(ExtensionMailLimiter::class)->forget(self::ID);

        $email = new EmailDefinition(
            type: 'ticket-reply',
            labelKey: 'ext.sdk_mail.emails.reply',
            descriptionKey: null,
            subject: 'New reply on {{ ticketTitle }}',
            variables: [
                new EmailVariableDefinition('ticketTitle', required: true),
                new EmailVariableDefinition('ticketUrl'),
            ],
        );

        $plan = $this->createStub(ExtensionRuntimePlanService::class);
        $plan->method('emailFor')->willReturnCallback(
            fn (string $id, string $type) => $id === self::ID && $type === 'ticket-reply' ? $email : null
        );
        $this->app->instance(ExtensionRuntimePlanService::class, $plan);
    }

    protected function tearDown(): void
    {
        app(ExtensionMailLimiter::class)->forget(self::ID);
        SettingsRepository::flushCache();

        parent::tearDown();
    }

    public function testASendIsLoggedUnderTheExtensionsKeyAndQueued(): void
    {
        $user = $this->user();

        $this->assertTrue(PackageMail::for(self::ID)->send('ticket-reply', $user, ['ticketTitle' => 'Server down']));

        $delivery = EmailDelivery::query()->sole();
        $this->assertSame('ext:sdk_mail:ticket-reply', $delivery->template_key);
        $this->assertSame('New reply on Server down', $delivery->subject);
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertSame(EmailDelivery::STATUS_QUEUED, $delivery->status);

        Queue::assertPushed(SendPanelMailJob::class, function (SendPanelMailJob $job) use ($user): bool {
            return $job->mail instanceof ExtensionMail
                && $job->mail->hasTo($user->email)
                && $job->mail->templateData() === ['ticketTitle' => 'Server down', 'userName' => $user->username, 'userEmail' => $user->email];
        });
    }

    public function testAnUndeclaredTypeIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('capabilities.emails');

        PackageMail::for(self::ID)->send('welcome', $this->user());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badVariables(): iterable
    {
        yield 'undeclared' => [['ticketTitle' => 'x', 'staffName' => 'y'], 'does not declare the variable "staffName"'];
        yield 'required missing' => [['ticketUrl' => 'https://x'], 'requires the variable "ticketTitle"'];
        yield 'required empty' => [['ticketTitle' => ''], 'requires the variable "ticketTitle"'];
        yield 'not a scalar' => [['ticketTitle' => ['a']], 'must be a string, number, boolean or null'];
    }

    /**
     * @param array<string, mixed> $variables
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badVariables')]
    public function testVariablesMustMatchTheDeclaration(array $variables, string $message): void
    {
        try {
            PackageMail::for(self::ID)->send('ticket-reply', $this->user(), $variables);
            $this->fail('Expected the variables to be refused.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(0, EmailDelivery::query()->count());
    }

    /** A redelivered hook or a retried job must not send twice. */
    public function testTheSameDedupeKeySendsOnce(): void
    {
        $user = $this->user();
        $mail = PackageMail::for(self::ID);

        $this->assertTrue($mail->send('ticket-reply', $user, ['ticketTitle' => 'a'], dedupeKey: 'reply-7'));
        $this->assertTrue($mail->send('ticket-reply', $user, ['ticketTitle' => 'a'], dedupeKey: 'reply-7'));
        $this->assertTrue($mail->send('ticket-reply', $user, ['ticketTitle' => 'a'], dedupeKey: 'reply-8'));

        $this->assertSame(2, EmailDelivery::query()->count());
        Queue::assertPushed(SendPanelMailJob::class, 2);
    }

    public function testATypeTheOperatorSwitchedOffIsSkipped(): void
    {
        EmailNotificationSetting::query()->create([
            'template_key' => 'ext:sdk_mail:ticket-reply',
            'enabled' => false,
            'category' => 'extension',
            'name' => 'SDK mail: ticket-reply',
        ]);

        $this->assertFalse(PackageMail::for(self::ID)->send('ticket-reply', $this->user(), ['ticketTitle' => 'a']));
        $this->assertSame(EmailDelivery::STATUS_SKIPPED, EmailDelivery::query()->sole()->status);
        $this->assertSame(0, app(ExtensionMailLimiter::class)->used(self::ID), 'A skipped type does not use the allowance.');
        Queue::assertNothingPushed();
    }

    public function testTheHourlyCeilingStopsARunawayExtension(): void
    {
        app(ExtensionMailLimiter::class)->setLimit(self::ID, 2);
        $user = $this->user();
        $mail = PackageMail::for(self::ID);

        $this->assertTrue($mail->send('ticket-reply', $user, ['ticketTitle' => 'a']));
        $this->assertTrue($mail->send('ticket-reply', $user, ['ticketTitle' => 'b']));
        $this->assertFalse($mail->send('ticket-reply', $user, ['ticketTitle' => 'c']));

        $last = EmailDelivery::query()->latest('id')->first();
        $this->assertSame(EmailDelivery::STATUS_SKIPPED, $last->status);
        $this->assertSame("Extension 'sdk_mail' reached its hourly email limit", $last->last_error);
        Queue::assertPushed(SendPanelMailJob::class, 2);
    }

    public function testNothingIsLoggedWhenDeliveryIsOff(): void
    {
        Setting::set('settings::modules:email:enabled', 'false');

        $this->assertFalse(PackageMail::for(self::ID)->send('ticket-reply', $this->user(), ['ticketTitle' => 'a']));
        $this->assertSame(0, EmailDelivery::query()->count());
    }

    /** Without events: the observer would otherwise queue its own welcome email. */
    private function user(): User
    {
        return User::withoutEvents(fn (): User => User::factory()->create(['email' => 'reader-' . uniqid() . '@m12labs.test-suite.net']));
    }
}
