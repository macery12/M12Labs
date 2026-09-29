<?php

namespace Everest\Console\Commands\Environment;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Everest\Traits\Commands\EnvironmentWriterTrait;

class DatabaseSettingsCommand extends Command
{
    use EnvironmentWriterTrait;

    protected $description = 'Configure database settings for the Panel.';

    protected $signature = 'p:environment:database
                            {--host= : The connection address for the MySQL server.}
                            {--port= : The connection port for the MySQL server.}
                            {--database= : The database to use.}
                            {--username= : Username to use when connecting.}
                            {--password= : Password to use for this database.}';

    protected array $variables = [];

    /**
     * Set once a connection test has failed and the operator chose to try
     * again. From then on every value is asked for, defaulting to what was
     * typed last time: an option passed on the command line is exactly what
     * just failed, and the configured values are what the operator is fixing.
     */
    private bool $retrying = false;

    /**
     * DatabaseSettingsCommand constructor.
     */
    public function __construct(private DatabaseManager $database, private Kernel $console)
    {
        parent::__construct();
    }

    /**
     * Handle command execution.
     *
     * @throws \Everest\Exceptions\PterodactylException
     */
    public function handle(): int
    {
        $this->output->note('It is highly recommended to not use "localhost" as your database host as we have seen frequent socket connection issues. If you want to use a local connection you should be using "127.0.0.1".');
        $this->variables['DB_HOST'] = $this->answer('host', 'Database Host', 'DB_HOST', config('database.connections.mysql.host', '127.0.0.1'));
        $this->variables['DB_PORT'] = $this->answer('port', 'Database Port', 'DB_PORT', config('database.connections.mysql.port', 3306));
        $this->variables['DB_DATABASE'] = $this->answer('database', 'Database Name', 'DB_DATABASE', config('database.connections.mysql.database', 'm12labsdb'));

        $this->output->note('Using the "root" account for MySQL connections is not only highly frowned upon, it is also not allowed by this application. You\'ll need to have created a MySQL user for this software.');
        $this->variables['DB_USERNAME'] = $this->answer('username', 'Database Username', 'DB_USERNAME', config('database.connections.mysql.username', 'm12labsuser'));

        $askForMySQLPassword = true;
        if (!$this->retrying && !empty(config('database.connections.mysql.password')) && $this->input->isInteractive()) {
            $this->variables['DB_PASSWORD'] = config('database.connections.mysql.password');
            $askForMySQLPassword = $this->confirm('It appears you already have a MySQL connection password defined, would you like to change it?');
        }

        if ($askForMySQLPassword) {
            $this->variables['DB_PASSWORD'] = ($this->retrying ? null : $this->option('password')) ?? $this->secret('Database Password');
        }

        try {
            $this->testMySQLConnection();
        } catch (\PDOException $exception) {
            $this->output->error(sprintf('Unable to connect to the MySQL server using the provided credentials. The error returned was "%s".', $exception->getMessage()));
            $this->output->error('Your connection credentials have NOT been saved. You will need to provide valid connection information before proceeding.');

            if ($this->confirm('Go back and try again?')) {
                // Purge, not disconnect. A disconnected connection is kept with
                // its old config and hands back a null PDO without connecting,
                // so the retry's test could never fail: whatever was typed the
                // second time was written to .env untested.
                $this->database->purge('_pterodactyl_command_test');
                $this->retrying = true;

                return $this->handle();
            }

            return 1;
        }

        $this->writeToEnvironment($this->variables);

        $this->info($this->console->output());

        return 0;
    }

    /**
     * An option on the first pass, otherwise a prompt whose default is the
     * previous answer (on a retry) or the configured value.
     */
    private function answer(string $option, string $question, string $variable, mixed $default): mixed
    {
        if (!$this->retrying && ($value = $this->option($option)) !== null) {
            return $value;
        }

        return $this->ask($question, $this->variables[$variable] ?? $default);
    }

    /**
     * Test that we can connect to the provided MySQL instance and perform a selection.
     */
    private function testMySQLConnection()
    {
        config()->set('database.connections._pterodactyl_command_test', [
            'driver' => 'mysql',
            'host' => $this->variables['DB_HOST'],
            'port' => $this->variables['DB_PORT'],
            'database' => $this->variables['DB_DATABASE'],
            'username' => $this->variables['DB_USERNAME'],
            'password' => $this->variables['DB_PASSWORD'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
        ]);

        $this->database->connection('_pterodactyl_command_test')->getPdo();
    }
}
