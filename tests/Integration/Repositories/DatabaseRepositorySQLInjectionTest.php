<?php

namespace Everest\Tests\Integration\Repositories;

use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Repositories\Eloquent\DatabaseRepository;

/**
 * Test that SQL injection vulnerabilities are properly mitigated in DatabaseRepository.
 */
class DatabaseRepositorySQLInjectionTest extends IntegrationTestCase
{
    private DatabaseRepository $repository;

    public function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(DatabaseRepository::class);
    }

    /**
     * Test that backticks in database names are properly escaped.
     */
    public function testCreateDatabaseEscapesBackticks()
    {
        $maliciousName = 'test`; DROP DATABASE panel; --';

        // Mock the database manager to intercept the SQL statement
        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                // MySQL escapes a backtick in a quoted identifier by doubling it, so
                // the whole value stays one (odd) database name.
                $this->assertSame('CREATE DATABASE IF NOT EXISTS `test``; DROP DATABASE panel; --`', $sql);

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->once()
            ->with('dynamic')
            ->andReturn($connectionMock);

        // Replace the database manager in the repository
        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->createDatabase($maliciousName);
    }

    /**
     * Test that single quotes and semicolons in passwords are properly escaped.
     */
    public function testCreateUserEscapesPasswordWithQuotesAndSemicolons()
    {
        $maliciousPassword = "'; DROP DATABASE panel; --";

        // Mock the PDO and connection
        $pdoMock = \Mockery::mock(\PDO::class);
        $pdoMock->shouldReceive('quote')
            ->once()
            ->with($maliciousPassword)
            // PDO quote() doubles single quotes instead of using backslash escaping
            ->andReturn("'''; DROP DATABASE panel; --'");

        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdoMock);

        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                // Verify the password is properly quoted (PDO doubles single quotes)
                $this->assertStringContainsString("IDENTIFIED BY '''; DROP DATABASE panel; --'", $sql);

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->twice()
            ->with('dynamic')
            ->andReturn($connectionMock);

        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->createUser('testuser', '%', $maliciousPassword, null);
    }

    /**
     * Test that backticks in usernames are properly escaped.
     */
    public function testCreateUserEscapesUsernameWithBackticks()
    {
        $maliciousUsername = 'user`@`localhost`; DROP DATABASE panel; --';

        $pdoMock = \Mockery::mock(\PDO::class);
        $pdoMock->shouldReceive('quote')
            ->once()
            ->with('password')
            ->andReturn("'password'");

        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdoMock);

        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                // Verify backticks in username are escaped
                $this->assertSame(
                    "CREATE USER `user``@``localhost``; DROP DATABASE panel; --`@`%` IDENTIFIED BY 'password'",
                    $sql
                );

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->twice()
            ->with('dynamic')
            ->andReturn($connectionMock);

        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->createUser($maliciousUsername, '%', 'password', null);
    }

    /**
     * Test that backticks in assignUserToDatabase are properly escaped.
     */
    public function testAssignUserToDatabaseEscapesParameters()
    {
        $maliciousDatabase = 'db`; GRANT ALL ON *.* TO hacker; --';
        $maliciousUsername = 'user`; DROP USER admin; --';
        $maliciousRemote = '%`; FLUSH PRIVILEGES; --';

        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                // Verify all parameters are escaped
                $this->assertStringEndsWith(
                    ' ON `db``; GRANT ALL ON *.* TO hacker; --`.* TO `user``; DROP USER admin; --`@`%``; FLUSH PRIVILEGES; --`',
                    $sql
                );

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->once()
            ->with('dynamic')
            ->andReturn($connectionMock);

        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->assignUserToDatabase($maliciousDatabase, $maliciousUsername, $maliciousRemote);
    }

    /**
     * Test that backticks in dropDatabase are properly escaped.
     */
    public function testDropDatabaseEscapesBackticks()
    {
        $maliciousName = 'test`; DROP DATABASE production; --';

        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                $this->assertSame('DROP DATABASE IF EXISTS `test``; DROP DATABASE production; --`', $sql);

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->once()
            ->with('dynamic')
            ->andReturn($connectionMock);

        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->dropDatabase($maliciousName);
    }

    /**
     * Test that backticks in dropUser are properly escaped.
     */
    public function testDropUserEscapesBackticks()
    {
        $maliciousUsername = 'user`; DROP DATABASE panel; --';
        $maliciousRemote = '%`; FLUSH PRIVILEGES; --';

        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                $this->assertSame('DROP USER IF EXISTS `user``; DROP DATABASE panel; --`@`%``; FLUSH PRIVILEGES; --`', $sql);

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->once()
            ->with('dynamic')
            ->andReturn($connectionMock);

        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->dropUser($maliciousUsername, $maliciousRemote);
    }

    /**
     * Backslash is literal inside a MySQL quoted identifier, so a leading one must
     * not "consume" the backtick escape: the backtick is still doubled.
     */
    public function testEscapeIdentifierLeavesBackslashesLiteral()
    {
        $maliciousName = 'test\\`; DROP DATABASE panel; --';

        $connectionMock = \Mockery::mock();
        $connectionMock->shouldReceive('statement')
            ->once()
            ->with(\Mockery::on(function ($sql) {
                $this->assertSame('CREATE DATABASE IF NOT EXISTS `test\\``; DROP DATABASE panel; --`', $sql);

                return true;
            }))
            ->andReturn(true);

        $dbManager = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $dbManager->shouldReceive('connection')
            ->once()
            ->with('dynamic')
            ->andReturn($connectionMock);

        $repository = new DatabaseRepository($this->app, $dbManager);
        $repository->setConnection('dynamic');

        $repository->createDatabase($maliciousName);
    }
}
