<?php

namespace Everest\Console\Commands\Email;

use Everest\Jobs\Email\ProcessDeferredEmailsJob;
use Illuminate\Console\Command;

class ProcessDeferredEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:process-deferred';

    /**
     * The console command description.
     */
    protected $description = 'Process deferred emails that are ready to be sent';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Processing deferred emails...');

        ProcessDeferredEmailsJob::dispatch();

        $this->info('Deferred emails processing job dispatched.');

        return 0;
    }
}
