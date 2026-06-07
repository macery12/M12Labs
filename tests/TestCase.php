<?php

namespace Everest\Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * @var list<callable>
     */
    private array $baselineErrorHandlers = [];

    /**
     * @var list<callable>
     */
    private array $baselineExceptionHandlers = [];

    /**
     * Setup tests.
     */
    public function setUp(): void
    {
        // PHPUnit snapshots handlers before setUp(). Capture that same baseline
        // so we can restore it after Laravel flushes handlers in tearDown().
        $this->baselineErrorHandlers = $this->captureActiveErrorHandlers();
        $this->baselineExceptionHandlers = $this->captureActiveExceptionHandlers();

        parent::setUp();

        $now = Carbon::now()->startOfSecond();

        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        // Why, you ask? If we don't force this to false it is possible for certain exceptions
        // to show their error message properly in the integration test output, but not actually
        // be setup correctly to display their message in production.
        //
        // If we expect a message in a test, and it isn't showing up (rather, showing the generic
        // "an error occurred" message), we can probably assume that the exception isn't one that
        // is recognized as being user viewable.
        config()->set('app.debug', false);

        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->string('key')->unique();
                $table->text('value');
            });
        }

        $this->setKnownUuidFactory();
    }

    /**
     * Tear down tests.
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restoreErrorHandlers($this->baselineErrorHandlers);
            $this->restoreExceptionHandlers($this->baselineExceptionHandlers);

            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * @return list<callable>
     */
    private function captureActiveErrorHandlers(): array
    {
        $handlers = [];

        while (true) {
            $previous = set_error_handler(static fn () => false);
            restore_error_handler();

            if ($previous === null) {
                break;
            }

            $handlers[] = $previous;
            restore_error_handler();
        }

        $handlers = array_reverse($handlers);

        foreach ($handlers as $handler) {
            set_error_handler($handler);
        }

        return $handlers;
    }

    /**
     * @return list<callable>
     */
    private function captureActiveExceptionHandlers(): array
    {
        $handlers = [];

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();

            if ($previous === null) {
                break;
            }

            $handlers[] = $previous;
            restore_exception_handler();
        }

        $handlers = array_reverse($handlers);

        foreach ($handlers as $handler) {
            set_exception_handler($handler);
        }

        return $handlers;
    }

    /**
     * @param list<callable> $handlers
     */
    private function restoreErrorHandlers(array $handlers): void
    {
        while (get_error_handler() !== null) {
            restore_error_handler();
        }

        foreach ($handlers as $handler) {
            set_error_handler($handler);
        }
    }

    /**
     * @param list<callable> $handlers
     */
    private function restoreExceptionHandlers(array $handlers): void
    {
        while (get_exception_handler() !== null) {
            restore_exception_handler();
        }

        foreach ($handlers as $handler) {
            set_exception_handler($handler);
        }
    }

    /**
     * Handles the known UUID handling in certain unit tests. Use the "KnownUuid" trait
     * in order to enable this ability.
     */
    public function setKnownUuidFactory()
    {
        // do nothing
    }
}
