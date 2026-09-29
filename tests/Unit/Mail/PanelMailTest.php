<?php

namespace Everest\Tests\Unit\Mail;

use Everest\Models\Node;
use Everest\Models\User;
use Everest\Models\Server;
use Everest\Mail\PanelMail;
use Everest\Tests\TestCase;
use Everest\Mail\NewLoginMail;
use Everest\Mail\PasswordResetMail;
use Everest\Mail\ServerCreatedMail;
use Illuminate\Support\Facades\File;
use Everest\Mail\PaymentReceivedMail;
use Everest\Events\Email\PaymentReceived;
use Everest\Events\Email\NewLoginDetected;
use Everest\Services\Email\EmailCatalogue;
use Everest\Events\Email\ServerCreatedEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Email\EmailSettingsReader;
use Everest\Events\Email\PasswordResetRequested;
use Everest\Tests\Unit\Services\Email\FakeEmailSettingsReader;
use Everest\Http\Controllers\Api\Application\EmailTemplateController;

class PanelMailTest extends TestCase
{
    private string $storage;

    public function setUp(): void
    {
        parent::setUp();

        // Rendering compiles into storage/framework/twig. As root in the live
        // checkout that would leave files the panel's own user cannot replace.
        $this->storage = sys_get_temp_dir() . '/panel-mail-test-' . bin2hex(random_bytes(4));
        $this->app->useStoragePath($this->storage);

        $this->app->instance(EmailSettingsReader::class, new FakeEmailSettingsReader([
            'from_email' => 'panel@m12labs.test-suite.net',
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function types(): iterable
    {
        foreach (array_keys(EmailCatalogue::all()) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('types')]
    public function testEveryTypeRendersItsTemplateWithItsData(string $key): void
    {
        $type = EmailCatalogue::find($key);
        $this->assertNotNull($type);
        $this->assertSame($key, $type->mailable::KEY);

        $mail = $this->sample($type->mailable);

        $this->assertNotSame('', $mail->subjectText());
        $this->assertFileExists(resource_path('views/emails/' . str_replace('.', '/', substr($type->view(), strlen('emails.'))) . '.twig'));
        $this->assertStringContainsString('Sample Person', $mail->renderBody());
    }

    /**
     * The editor documents each template's variables by hand; they have to
     * be the ones the Mailable actually passes.
     */
    #[DataProvider('types')]
    public function testTheTemplateEditorDocumentsExactlyTheMailablesVariables(string $key): void
    {
        $templates = (new \ReflectionClassConstant(EmailTemplateController::class, 'TEMPLATES'))->getValue();
        $documented = array_column($templates[$key]['variables'] ?? [], 'name');

        $mailable = EmailCatalogue::find($key)?->mailable;
        $this->assertNotNull($mailable);
        $parameters = array_map(fn (\ReflectionParameter $p) => $p->getName(), (new \ReflectionMethod($mailable, '__construct'))->getParameters());

        sort($documented);
        sort($parameters);
        $this->assertSame($parameters, $documented);
    }

    public function testPasswordResetIsBuiltFromItsEvent(): void
    {
        $mail = PasswordResetMail::fromEvent(new PasswordResetRequested($this->user(), 'https://panel.test/reset/abc', 'corr-1'));

        $this->assertSame(['userName' => 'sample', 'resetUrl' => 'https://panel.test/reset/abc', 'expiresIn' => '60 minutes'], $mail->templateData());
        $this->assertSame('Reset Your Password', $mail->subjectText());
    }

    public function testNewLoginTimeIsInThePanelTimezone(): void
    {
        config(['app.timezone' => 'America/Chicago']);

        $mail = NewLoginMail::fromEvent(new NewLoginDetected(
            $this->user(),
            '192.0.2.1',
            'Firefox',
            'corr-2',
            new \DateTimeImmutable('2026-09-29 15:00:00', new \DateTimeZone('UTC')),
        ));

        $this->assertSame('September 29, 2026 10:00 AM CDT', $mail->loginTime);
        $this->assertSame('Unknown', $mail->location);
    }

    public function testServerCreatedCarriesTheServerLink(): void
    {
        $server = new Server(['name' => 'Survival']);
        $server->uuidShort = 'a1b2c3d4';
        $server->setRelation('node', new Node(['name' => 'US East']));

        $mail = ServerCreatedMail::fromEvent(new ServerCreatedEmail($server, $this->user(), 'corr-3'));

        $this->assertSame('a1b2c3d4', $mail->serverId);
        $this->assertStringEndsWith('/server/a1b2c3d4', $mail->serverUrl);
        $this->assertSame('US East', $mail->nodeLocation);
    }

    /**
     * Billing passes the cached PDF as an absolute path with no disk, which
     * the old listener skipped, so receipts never carried the invoice.
     */
    public function testTheReceiptAttachesTheInvoiceFromAnAbsolutePath(): void
    {
        File::ensureDirectoryExists($this->storage);
        $pdf = $this->storage . '/INV-1.pdf';
        file_put_contents($pdf, '%PDF-1.4 sample');

        $mail = PaymentReceivedMail::fromEvent(new PaymentReceived(
            user: $this->user(),
            amount: 9.99,
            currency: 'usd',
            paymentMethod: 'Stripe',
            invoiceId: 'INV-1',
            billingDays: 30,
            invoiceFilePath: $pdf,
            invoiceFileName: 'INV-1.pdf',
        ));

        $this->assertSame('USD', $mail->currency);
        $this->assertSame('Monthly', $mail->billingCycle);
        $this->assertArrayNotHasKey('invoicePath', $mail->templateData(), 'The PDF path is not a template variable.');

        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('INV-1.pdf', $attachments[0]->as);
        $this->assertSame('application/pdf', $attachments[0]->mime);

        unlink($pdf);
        $this->assertSame([], $mail->attachments(), 'An evicted PDF must not stop the receipt.');
    }

    public function testTheTextPartIsDerivedFromTheHtml(): void
    {
        $this->assertSame("Hello\n\nWorld & more", PanelMail::htmlToText('<style>p{}</style><p>Hello</p><div>World &amp; more</div>'));
    }

    private function user(): User
    {
        return new User(['username' => 'sample', 'email' => 'sample@m12labs.test-suite.net']);
    }

    /**
     * @param class-string<PanelMail> $class
     */
    private function sample(string $class): PanelMail
    {
        $arguments = [];

        foreach ((new \ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

            $arguments[$parameter->getName()] = match (true) {
                $parameter->getName() === 'userName' => 'Sample Person',
                $name === 'bool' => false,
                $name === 'int' => 30,
                default => 'sample ' . $parameter->getName(),
            };
        }

        return new $class(...$arguments);
    }
}
