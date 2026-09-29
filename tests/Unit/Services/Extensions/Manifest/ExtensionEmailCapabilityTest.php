<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\Definitions\EmailDefinition;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityFileValidator;
use Everest\Services\Extensions\Manifest\Definitions\EmailVariableDefinition;

/**
 * `capabilities.emails` — mail a package sends through the panel's mailer.
 *
 * Each type is the package speaking to the operator's users from the
 * operator's address, so it is approved at install like any other surface,
 * pairs with exactly one shipped template, and has to keep the projection of
 * every package that declares none byte-identical.
 */
class ExtensionEmailCapabilityTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /**
     * @param array<string, mixed> $capabilities
     * @param array<int, string> $files
     *
     * @return array<string, mixed>
     */
    private function manifest(array $capabilities = [], array $files = ['app/Extensions/Packages/demo/Support/Helper.php']): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'A demo package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false, 'allowedNests' => [], 'allowedEggs' => [], 'settings' => []],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.4 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => array_map(fn (string $path): array => ['path' => $path, 'sha256' => str_repeat('a', 64)], $files),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function type(array $overrides = []): array
    {
        return array_merge([
            'type' => 'ticket-reply',
            'labelKey' => 'ext.demo.emails.ticketReply',
            'subject' => 'New reply on {{ ticketTitle }}',
            'variables' => [
                ['name' => 'ticketTitle', 'description' => "The ticket's title", 'example' => 'Server down', 'required' => true],
                ['name' => 'ticketUrl', 'example' => 'https://panel.example.com/tickets/4'],
            ],
        ], $overrides);
    }

    public function testADeclaredTypeIsParsed(): void
    {
        $email = $this->parser->parse($this->manifest(['emails' => [$this->type()]]))->capabilities->email('ticket-reply');

        $this->assertNotNull($email);
        $this->assertSame('ext:demo:ticket-reply', $email->key('demo'));
        $this->assertSame('emails/ticket-reply.twig', $email->templatePath());
        $this->assertTrue($email->variable('ticketTitle')?->required);
        $this->assertFalse($email->variable('ticketUrl')?->required);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'type not a slug' => [['type' => 'Ticket_Reply']];
        yield 'label outside the package namespace' => [['labelKey' => 'admin.email.title']];
        yield 'subject spans lines' => [['subject' => "Hello\nBcc: someone@example.com"]];
        yield 'subject too long for the log' => [['subject' => str_repeat('a', 192)]];
        yield 'subject names an undeclared variable' => [['subject' => 'Reply from {{ staffName }}']];
        yield 'variable the panel provides' => [['variables' => [['name' => 'userName']]]];
        yield 'variable name with a dot' => [['variables' => [['name' => 'ticket.title']]]];
        yield 'duplicate variable' => [['variables' => [['name' => 'a'], ['name' => 'a']]]];
        yield 'required written as a string' => [['variables' => [['name' => 'a', 'required' => 'true']]]];
        yield 'unknown key' => [['from' => 'someone@example.com']];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTypes')]
    public function testAMalformedTypeIsRefused(array $overrides): void
    {
        $this->expectException(DisplayException::class);

        $this->parser->parse($this->manifest(['emails' => [$this->type($overrides)]]));
    }

    public function testDuplicateTypesAreRefused(): void
    {
        $this->expectException(DisplayException::class);

        $this->parser->parse($this->manifest(['emails' => [$this->type(), $this->type()]]));
    }

    /** `userName` and `appName` are always in the subject's reach. */
    public function testASubjectMayUseTheRecipientAndPanelName(): void
    {
        $email = $this->parser->parse($this->manifest(['emails' => [$this->type(['subject' => '{{ appName }}: hello {{ userName }}'])]]))
            ->capabilities->email('ticket-reply');

        $this->assertSame('Panel: hello jane', $email?->subjectWith(['appName' => 'Panel', 'userName' => 'jane']));
    }

    /** A value cannot smuggle a second header line into the subject. */
    public function testFilledSubjectsAreOneLineAndFitTheLog(): void
    {
        $email = new EmailDefinition('reply', 'ext.demo.reply', null, 'Re: {{ title }}', [new EmailVariableDefinition('title')]);

        $this->assertSame('Re: a Bcc: x@example.com', $email->subjectWith(['title' => "a\r\nBcc: x@example.com"]));
        $this->assertSame(191, mb_strlen($email->subjectWith(['title' => str_repeat('é', 400)])));
    }

    public function testTheProjectionDoesNotDependOnAuthorOrdering(): void
    {
        $one = $this->parser->parse($this->manifest(['emails' => [$this->type(['type' => 'beta']), $this->type(['type' => 'alpha'])]]));
        $two = $this->parser->parse($this->manifest(['emails' => [$this->type(['type' => 'alpha']), $this->type(['type' => 'beta'])]]));

        $this->assertSame($one->capabilities->hash(), $two->capabilities->hash());
    }

    /**
     * See ExtensionStreamCapabilityTest: the stored projection is re-hashed on
     * every plan build, so an unconditional key would take every installed
     * package inert on upgrade.
     */
    public function testDeclaringNoEmailsLeavesTheProjectionByteIdentical(): void
    {
        $this->assertArrayNotHasKey('emails', (new ExtensionCapabilitySet(clientRoutes: true))->jsonSerialize());
    }

    /**
     * Pinned so a change to the shape has to be a decision: if this fails,
     * every installed package that sends email is about to become inert.
     */
    public function testTheProjectionShapeIsPinned(): void
    {
        $set = new ExtensionCapabilitySet(emails: [
            new EmailDefinition('reply', 'ext.demo.reply', null, 'Re: {{ title }}', [new EmailVariableDefinition('title', 'Title', 'Hi', true)]),
        ]);

        $this->assertSame([
            'type' => 'reply',
            'labelKey' => 'ext.demo.reply',
            'subject' => 'Re: {{ title }}',
            'variables' => [['name' => 'title', 'description' => 'Title', 'example' => 'Hi', 'required' => true]],
        ], $set->jsonSerialize()['emails'][0]);
    }

    /** What was approved is what comes back out of storage, hash and all. */
    public function testTheStoredProjectionHydratesToTheSameHash(): void
    {
        $set = $this->parser->parse($this->manifest(['emails' => [$this->type()]]))->capabilities;
        $stored = json_decode(json_encode($set), true);

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($stored);

        $this->assertSame($set->hash(), $hydrated?->hash());
    }

    /** A type edited in the database into a path does not survive hydration. */
    public function testATamperedTypeDropsOutOfTheStoredProjection(): void
    {
        $set = $this->parser->parse($this->manifest(['emails' => [$this->type()]]))->capabilities;
        $stored = json_decode(json_encode($set), true);
        $stored['emails'][0]['type'] = '../../auth/password-reset';

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($stored);

        $this->assertSame([], $hydrated->emails);
        $this->assertNotSame($set->hash(), $hydrated->hash());
    }

    public function testAddingATypeOnUpdateIsAnEscalation(): void
    {
        $email = new EmailDefinition('reply', 'ext.demo.reply', null, 'Re: hello');

        $this->assertTrue(ExtensionCapabilityDiff::between(new ExtensionCapabilitySet(), new ExtensionCapabilitySet(emails: [$email]))->isEscalation());
    }

    /** The operator cannot edit a subject, so a new one has to be shown. */
    public function testChangingASubjectIsAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(emails: [new EmailDefinition('reply', 'ext.demo.reply', null, 'Re: hello')]),
            new ExtensionCapabilitySet(emails: [new EmailDefinition('reply', 'ext.demo.reply', null, 'Urgent: verify your password')]),
        );

        $this->assertTrue($diff->isEscalation());
    }

    public function testRemovingATypeIsNotAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(emails: [new EmailDefinition('reply', 'ext.demo.reply', null, 'Re: hello')]),
            new ExtensionCapabilitySet(),
        );

        $this->assertFalse($diff->isEscalation());
    }

    public function testAnUnverifiedPackageMayNotSendEmail(): void
    {
        $restricted = (new ExtensionSignatureService(new ExtensionManifestCanonicalizer()))->restrictedCapabilitiesForUnverified(
            new ExtensionCapabilitySet(emails: [new EmailDefinition('reply', 'ext.demo.reply', null, 'Re: hello')])
        );

        $this->assertContains('emails', $restricted);
    }

    public function testADeclaredTypeMustShipItsTemplate(): void
    {
        $manifest = $this->parser->parse($this->manifest(['emails' => [$this->type()]]));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('app/Extensions/Packages/demo/emails/ticket-reply.twig');

        (new ExtensionCapabilityFileValidator())->assertMatchesFiles($manifest);
    }

    public function testAShippedTemplateMustBeDeclared(): void
    {
        $manifest = $this->parser->parse($this->manifest([], ['app/Extensions/Packages/demo/emails/welcome.twig']));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('capabilities.emails');

        (new ExtensionCapabilityFileValidator())->assertMatchesFiles($manifest);
    }

    public function testADeclaredTypeWithItsTemplateIsAccepted(): void
    {
        $manifest = $this->parser->parse($this->manifest(['emails' => [$this->type()]], ['app/Extensions/Packages/demo/emails/ticket-reply.twig']));

        (new ExtensionCapabilityFileValidator())->assertMatchesFiles($manifest);

        $this->assertSame(1, $manifest->capabilities->summary()['emails']);
    }
}
