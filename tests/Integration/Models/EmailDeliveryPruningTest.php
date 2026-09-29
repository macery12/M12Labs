<?php

namespace Everest\Tests\Integration\Models;

use Everest\Models\Setting;
use Illuminate\Support\Str;
use Everest\Models\EmailDelivery;
use Illuminate\Support\Facades\DB;
use Everest\Models\EmailDeliveryAttempt;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Repositories\Eloquent\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * The delivery log used to be kept forever.
 */
class EmailDeliveryPruningTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        SettingsRepository::flushCache();

        parent::tearDown();
    }

    public function testRowsPastTheRetentionGoWithTheirAttempts(): void
    {
        Setting::set('settings::modules:email:log_retention_days', '7');

        $old = $this->delivery(now()->subDays(8));
        $recent = $this->delivery(now()->subDays(6));

        $this->artisan('model:prune', ['--model' => [EmailDelivery::class]])->assertSuccessful();

        $this->assertNull(EmailDelivery::query()->find($old->id));
        $this->assertNotNull(EmailDelivery::query()->find($recent->id));
        $this->assertSame(0, EmailDeliveryAttempt::query()->where('delivery_id', $old->id)->count());
        $this->assertSame(1, EmailDeliveryAttempt::query()->where('delivery_id', $recent->id)->count());
    }

    public function testTheDefaultRetentionIsThirtyDays(): void
    {
        $kept = $this->delivery(now()->subDays(29));
        $pruned = $this->delivery(now()->subDays(31));

        $this->artisan('model:prune', ['--model' => [EmailDelivery::class]])->assertSuccessful();

        $this->assertNotNull(EmailDelivery::query()->find($kept->id));
        $this->assertNull(EmailDelivery::query()->find($pruned->id));
    }

    public function testThePruneIsScheduledDaily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain("model:prune --model='" . EmailDelivery::class . "'");
    }

    private function delivery(\DateTimeInterface $at): EmailDelivery
    {
        $delivery = EmailDelivery::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'template_key' => 'auth.new_login',
            'recipient' => 'person@m12labs.test-suite.net',
            'subject' => 'New Login Detected',
            'provider' => 'smtp',
            'status' => EmailDelivery::STATUS_SENT,
        ]);

        DB::table('email_deliveries')->where('id', $delivery->id)->update(['created_at' => $at]);

        EmailDeliveryAttempt::query()->create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'provider' => 'smtp',
            'status' => EmailDelivery::STATUS_SENT,
            'success' => true,
        ]);

        return $delivery;
    }
}
