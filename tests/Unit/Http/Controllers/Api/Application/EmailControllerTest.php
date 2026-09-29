<?php

namespace Everest\Tests\Unit\Http\Controllers\Api\Application;

use Everest\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Everest\Services\Email\MailFailure;
use Everest\Services\Email\EmailPolicyService;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Email\EmailSettingsReader;
use Everest\Services\Email\EmailVerificationGate;
use Everest\Services\Email\PanelMailerConfigurator;
use Everest\Http\Controllers\Api\Application\EmailController;

class EmailControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    public function testAConnectionCheckReportsItsProviderAndTestType(): void
    {
        $payload = $this->invoke('sent', 'smtp', 'connection_test', 'msg-123')->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame('smtp', $payload['transport']);
        $this->assertSame('smtp', $payload['provider']);
        $this->assertSame('msg-123', $payload['message_id']);
        $this->assertSame('connection', $payload['test_type']);
        $this->assertArrayHasKey('tested_at', $payload);
        $this->assertArrayNotHasKey('sent_at', $payload);
    }

    public function testADeliveryTestReportsWhoItWentTo(): void
    {
        $payload = $this->invoke('sent', 'resend', 'send_test', 're_1', 'admin@m12labs.test-suite.net')->getData(true);

        $this->assertSame('resend', $payload['provider']);
        $this->assertSame('delivery', $payload['test_type']);
        $this->assertSame('admin@m12labs.test-suite.net', $payload['recipient']);
        $this->assertArrayHasKey('sent_at', $payload);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function failures(): iterable
    {
        yield 'settings incomplete' => [MailFailure::KIND_CONFIG, 'SMTP_CONFIG_INVALID', 422];
        yield 'bad credentials' => [MailFailure::KIND_AUTH, 'SMTP_AUTH_FAILED', 422];
        yield 'refused' => [MailFailure::KIND_REJECTED, 'SMTP_SEND_TEST_REJECTED', 422];
        yield 'provider down' => [MailFailure::KIND_TRANSIENT, 'SMTP_SEND_TEST_FAILED', 502];
    }

    #[DataProvider('failures')]
    public function testAFailureCarriesACodeTheAdminCanActOn(string $kind, string $code, int $status): void
    {
        $response = $this->invoke('failed', new MailFailure($kind, 'It went wrong.', 550), 'smtp', 'send_test', 'ops@m12labs.test-suite.net');
        $payload = $response->getData(true);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame($code, $payload['error']['code']);
        $this->assertSame('It went wrong.', $payload['error']['message']);
        $this->assertSame('ops@m12labs.test-suite.net', $payload['recipient']);
        $this->assertSame('delivery', $payload['test_type']);
    }

    public function testASkippedTestSaysWhy(): void
    {
        $payload = $this->invoke('skipped', 'disabled', 'smtp', 'ops@m12labs.test-suite.net')->getData(true);

        $this->assertSame('skipped', $payload['status']);
        $this->assertSame('EMAIL_DISABLED', $payload['error']['code']);
    }

    private function invoke(string $method, mixed ...$arguments): JsonResponse
    {
        $controller = new EmailController(
            \Mockery::mock(EmailVerificationGate::class),
            \Mockery::mock(EmailSettingsReader::class),
            \Mockery::mock(EmailPolicyService::class),
            \Mockery::mock(PanelMailerConfigurator::class),
        );

        /** @var JsonResponse $response */
        $response = (new \ReflectionMethod($controller, $method))->invokeArgs($controller, $arguments);

        return $response;
    }
}
