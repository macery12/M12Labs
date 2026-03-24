<?php

namespace Everest\Tests\Unit\Services\Security;

use Everest\Tests\TestCase;
use Everest\Services\Security\LogSanitizer;

class LogSanitizerTest extends TestCase
{
    public function testRedactsNestedSensitivePayload(): void
    {
        $payload = [
            'token' => 'secret-token',
            'nested' => [
                'authorization' => 'Bearer abc123',
                'safe' => 'value',
            ],
        ];

        $sanitized = LogSanitizer::redactSensitivePayload($payload);

        $this->assertSame(LogSanitizer::REDACTED_VALUE, $sanitized['token']);
        $this->assertSame(LogSanitizer::REDACTED_VALUE, $sanitized['nested']['authorization']);
        $this->assertSame('value', $sanitized['nested']['safe']);
    }

    public function testMasksIdentifiersWithoutExposingFullValue(): void
    {
        $masked = LogSanitizer::maskIdentifier('PAYPAL-ORDER-1234567890');

        $this->assertSame('PAYP...7890', $masked);
        $this->assertSame(LogSanitizer::REDACTED_VALUE, LogSanitizer::maskIdentifier('short'));
    }

    public function testSanitizeUrlRedactsSensitiveQueryValues(): void
    {
        $sanitized = LogSanitizer::sanitizeUrlForLogging('https://example.com/callback?token=abc123&processor=paypal');

        $this->assertSame('https://example.com/callback?token=%5BREDACTED%5D&processor=paypal', $sanitized);
    }

    public function testSummarizeProviderPayloadKeepsOnlySafeSummaryFields(): void
    {
        $summary = LogSanitizer::summarizeProviderPayload([
            'error' => 'invalid_client',
            'message' => 'Credentials rejected',
            'access_token' => 'secret',
            'details' => [['issue' => 'bad_request']],
        ]);

        $this->assertSame('invalid_client', $summary['error']);
        $this->assertSame('Credentials rejected', $summary['message']);
        $this->assertSame(1, $summary['detail_count']);
        $this->assertArrayNotHasKey('access_token', $summary);
    }
}
