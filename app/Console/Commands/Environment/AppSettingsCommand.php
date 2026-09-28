<?php

namespace Everest\Console\Commands\Environment;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Everest\Traits\Commands\EnvironmentWriterTrait;

class AppSettingsCommand extends Command
{
    use EnvironmentWriterTrait;

    protected $description = 'Configure basic environment settings for the Panel.';

    protected $signature = 'p:environment:setup
                            {--new-salt : Whether or not to generate a new salt for Hashids.}
                            {--author= : The email that services created on this instance should be linked to.}
                            {--url= : The URL that this Panel is running on.}
                            {--timezone= : The timezone to use for Panel times.}
                            {--cache= : Deprecated; the cache is always Redis. Only "redis" is accepted.}
                            {--session= : Deprecated; sessions are always Redis. Only "redis" is accepted.}
                            {--redis-host= : Redis host to use for connections.}
                            {--redis-pass= : Password used to connect to redis.}
                            {--redis-port= : Port to connect to redis over.}
                            {--settings-ui= : Enable or disable the settings UI (default: enabled).}
                            {--telemetry= : Deprecated and ignored; the panel sends no telemetry.}';

    protected array $variables = [];

    /**
     * AppSettingsCommand constructor.
     */
    public function __construct(private Kernel $console)
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
        if (empty(config('hashids.salt')) || $this->option('new-salt')) {
            $this->variables['HASHIDS_SALT'] = str_random(20);
        }

        $this->output->comment('Provide the email address that eggs exported by this Panel should be from. This should be a valid email address.');
        $this->variables['APP_SERVICE_AUTHOR'] = $this->option('author') ?? $this->ask(
            'Egg Author Email',
            config('everest.service.author', 'unknown@unknown.com')
        );

        if (!filter_var($this->variables['APP_SERVICE_AUTHOR'], FILTER_VALIDATE_EMAIL)) {
            $this->output->error('The service author email provided is invalid.');

            return 1;
        }

        $this->output->comment('The application URL MUST begin with https:// or http:// depending on if you are using SSL or not. If you do not include the scheme your emails and other content will link to the wrong location.');
        $this->variables['APP_URL'] = $this->option('url') ?? $this->ask(
            'Application URL',
            config('app.url', 'https://example.com')
        );

        $this->output->comment('The timezone should match one of PHP\'s supported timezones. If you are unsure, please reference https://php.net/manual/en/timezones.php.');
        $this->variables['APP_TIMEZONE'] = $this->option('timezone') ?? $this->anticipate(
            'Application Timezone',
            \DateTimeZone::listIdentifiers(),
            config('app.timezone')
        );

        // The cache is not disposable here: it holds extension lifecycle
        // leases, queue admissions and rate limits, and Horizon only
        // supervises Redis queues. Every one of these is Redis, so there is
        // nothing to choose.
        foreach (['cache' => 'CACHE_DRIVER', 'session' => 'SESSION_DRIVER'] as $option => $variable) {
            if (!in_array($this->option($option), [null, 'redis'], true)) {
                $this->output->error("--{$option} only accepts \"redis\"; the panel requires Redis for its cache, sessions and queue.");

                return 1;
            }

            $this->variables[$variable] = 'redis';
        }

        // Horizon can only supervise Redis queues, so allowing another driver
        // here would leave a fresh installation with no queue workers.
        $this->variables['QUEUE_CONNECTION'] = 'redis';
        $this->output->comment('Cache, sessions and queue: Redis (required).');

        // Not asked: a new operator cannot weigh it, and answering "no"
        // silently turns off the Features page and every admin setting. A
        // deployment that deliberately locks settings passes --settings-ui=false.
        $this->variables['APP_ENVIRONMENT_ONLY'] = $this->option('settings-ui') === 'false' ? 'true' : 'false';

        // Make sure session cookies are set as "secure" when using HTTPS
        if (str_starts_with($this->variables['APP_URL'], 'https://')) {
            $this->variables['SESSION_SECURE_COOKIE'] = 'true';
        }

        $this->checkForRedis();
        $this->writeToEnvironment($this->variables);

        $this->info($this->console->output());

        return 0;
    }

    /**
     * Request the Redis connection details. Always asked: cache, sessions and
     * the queue all run on Redis.
     */
    private function checkForRedis()
    {
        $this->output->note('Please provide your Redis connection information below. In most cases you can use the defaults provided unless you have modified your setup.');
        $this->variables['REDIS_HOST'] = $this->option('redis-host') ?? $this->ask(
            'Redis Host',
            config('database.redis.default.host')
        );

        $askForRedisPassword = true;
        if (!empty(config('database.redis.default.password'))) {
            $this->variables['REDIS_PASSWORD'] = config('database.redis.default.password');
            $askForRedisPassword = $this->confirm('It seems a password is already defined for Redis, would you like to change it?');
        }

        if ($askForRedisPassword) {
            $this->output->comment('By default a Redis server instance has no password as it is running locally and inaccessible to the outside world. If this is the case, simply hit enter without entering a value.');
            $this->variables['REDIS_PASSWORD'] = $this->option('redis-pass') ?? $this->output->askHidden(
                'Redis Password'
            );
        }

        if (empty($this->variables['REDIS_PASSWORD'])) {
            $this->variables['REDIS_PASSWORD'] = 'null';
        }

        $this->variables['REDIS_PORT'] = $this->option('redis-port') ?? $this->ask(
            'Redis Port',
            config('database.redis.default.port')
        );
    }
}
