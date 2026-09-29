<?php

namespace Everest\Tests\Unit\Console\Commands\Environment;

use Everest\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Everest\Console\Commands\Environment\AppSettingsCommand;

/**
 * `p:environment:setup` used to offer file and database cache/session drivers
 * the panel cannot run on, a telemetry prompt nothing reads, and a "UI
 * settings editor?" question whose "no" silently disables every admin
 * setting. None of those are questions any more.
 */
class AppSettingsCommandTest extends TestCase
{
    /** @var array<string, string> */
    public static array $written = [];

    public function setUp(): void
    {
        parent::setUp();

        self::$written = [];
        config()->set('database.redis.default.password', null);

        // Never the real .env: capture what would have been written.
        $this->app->make(Kernel::class)->registerCommand(new class ($this->app->make(Kernel::class)) extends AppSettingsCommand {
            public function writeToEnvironment(array $values = []): void
            {
                AppSettingsCommandTest::$written = $values;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function setupOptions(array $extra = []): array
    {
        return array_merge([
            '--author' => 'eggs@m12labs.test-suite.net',
            '--url' => 'https://panel.m12labs.test-suite.net',
            '--timezone' => 'UTC',
            '--redis-host' => '127.0.0.1',
            '--redis-pass' => '',
            '--redis-port' => '6379',
        ], $extra);
    }

    public function testCacheSessionsAndQueueAreAlwaysRedisAndTheSettingsUiIsOn(): void
    {
        $this->artisan('p:environment:setup', $this->setupOptions())->assertSuccessful();

        $this->assertSame('redis', self::$written['CACHE_DRIVER']);
        $this->assertSame('redis', self::$written['SESSION_DRIVER']);
        $this->assertSame('redis', self::$written['QUEUE_CONNECTION']);
        $this->assertSame('false', self::$written['APP_ENVIRONMENT_ONLY']);
        $this->assertArrayNotHasKey('PTERODACTYL_TELEMETRY_ENABLED', self::$written);
    }

    public function testADeploymentCanStillLockSettingsToTheEnvironment(): void
    {
        $this->artisan('p:environment:setup', $this->setupOptions(['--settings-ui' => 'false']))->assertSuccessful();

        $this->assertSame('true', self::$written['APP_ENVIRONMENT_ONLY']);
    }

    /**
     * `APP_TIMEZONE=Chicago` makes every artisan command fatal at boot, this one
     * included, so a bad value has to be refused before it is written.
     */
    public function testAnUnknownTimezoneIsRefused(): void
    {
        $this->artisan('p:environment:setup', $this->setupOptions(['--timezone' => 'Chicago']))
            ->expectsOutputToContain('America/Chicago')
            ->assertFailed();

        $this->assertSame([], self::$written);
    }

    public function testAUrlWithoutASchemeIsRefused(): void
    {
        $this->artisan('p:environment:setup', $this->setupOptions(['--url' => 'panel.example.com']))
            ->expectsOutputToContain('https://')
            ->assertFailed();

        $this->assertSame([], self::$written);
    }

    public function testAnInteractiveTimezoneIsAskedAgain(): void
    {
        $options = $this->setupOptions();
        unset($options['--timezone']);

        $this->artisan('p:environment:setup', $options)
            ->expectsQuestion('Application Timezone', 'Chicago')
            ->expectsQuestion('Application Timezone', 'America/Chicago')
            ->assertSuccessful();

        $this->assertSame('America/Chicago', self::$written['APP_TIMEZONE']);
    }

    public function testANonRedisDriverIsRefused(): void
    {
        $this->artisan('p:environment:setup', $this->setupOptions(['--cache' => 'file']))->assertFailed();

        $this->assertSame([], self::$written);
    }
}
