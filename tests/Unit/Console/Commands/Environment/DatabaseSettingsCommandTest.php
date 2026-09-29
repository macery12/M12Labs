<?php

namespace Everest\Tests\Unit\Console\Commands\Environment;

use Everest\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Everest\Console\Commands\Environment\DatabaseSettingsCommand;

/**
 * "Go back and try again?" used to disconnect the test connection rather than
 * purge it. A disconnected connection hands back a null PDO without trying, so
 * the retry could never fail and wrote whatever was typed second to .env.
 */
class DatabaseSettingsCommandTest extends TestCase
{
    /** @var array<string, string>|null */
    public static ?array $written = null;

    public function setUp(): void
    {
        parent::setUp();

        self::$written = null;
        config()->set('database.connections.mysql.password', null);

        // Never the real .env: capture what would have been written.
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new class ($this->app->make(DatabaseManager::class), $kernel) extends DatabaseSettingsCommand {
            public function writeToEnvironment(array $values = []): void
            {
                DatabaseSettingsCommandTest::$written = $values;
            }
        });
    }

    public function testARetryReallyTestsTheNewCredentials(): void
    {
        // Port 1 on loopback refuses immediately, so both attempts must fail.
        $this->artisan('p:environment:database', [
            '--host' => '127.0.0.1',
            '--port' => '1',
            '--database' => 'panel',
            '--username' => 'panel',
            '--password' => 'wrong',
        ])
            ->expectsConfirmation('Go back and try again?', 'yes')
            ->expectsQuestion('Database Host', '127.0.0.1')
            ->expectsQuestion('Database Port', '1')
            ->expectsQuestion('Database Name', 'panel')
            ->expectsQuestion('Database Username', 'panel')
            ->expectsQuestion('Database Password', 'still-wrong')
            ->expectsConfirmation('Go back and try again?', 'no')
            ->assertFailed();

        $this->assertNull(self::$written);
    }
}
