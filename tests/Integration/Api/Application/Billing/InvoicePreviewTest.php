<?php

namespace Everest\Tests\Integration\Api\Application\Billing;

use Everest\Services\Billing\InvoiceSettingsService;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class InvoicePreviewTest extends ApplicationApiIntegrationTestCase
{
    public function testPreviewRendersAPdfWithoutAdvancingTheSequence(): void
    {
        $settings = $this->app->make(InvoiceSettingsService::class)->get();
        $before = $settings->invoice_sequence;

        $response = $this->postJson('/api/application/billing/invoice-settings/preview', [
            'company_name' => 'Preview Co',
            'invoice_prefix' => 'PRE',
            'currency' => 'eur',
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertSame($before, $settings->fresh()->invoice_sequence);
    }

    public function testPreviewRejectsAnInvalidPrefix(): void
    {
        $this->postJson('/api/application/billing/invoice-settings/preview', ['invoice_prefix' => 'bad prefix'])
            ->assertStatus(422);
    }
}
