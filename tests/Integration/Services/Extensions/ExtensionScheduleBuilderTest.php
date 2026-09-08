<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Console\Scheduling\Schedule;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionScheduleBuilder;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;

/**
 * What a package's schedule.php is actually allowed to do.
 *
 * Handing a package the real Schedule hands it exec() and call(): arbitrary
 * code on the panel host, at an interval of the package's choosing, outside
 * every capability its manifest declares. The builder is the narrow surface
 * that replaces it, and these tests pin the edges of that surface.
 */
class ExtensionScheduleBuilderTest extends IntegrationTestCase
{
    private function builder(string ...$commands): ExtensionScheduleBuilder
    {
        return new ExtensionScheduleBuilder(
            app(Schedule::class),
            'demo',
            new ExtensionCapabilitySet(schedule: true, commands: $commands),
        );
    }

    public function testADeclaredCommandIsScheduled(): void
    {
        $task = $this->builder('p:ext:demo:sync')->command('p:ext:demo:sync')->hourly();

        $this->assertInstanceOf(\Everest\Services\Extensions\ExtensionScheduledTask::class, $task);
    }

    /**
     * The manifest is the contract. A command the package never declared is not
     * schedulable even though the package could obviously call it directly —
     * the point is that the declared surface is what an administrator approved.
     */
    public function testAnUndeclaredCommandIsRefused(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('did not declare');

        $this->builder('p:ext:demo:sync')->command('p:ext:demo:other');
    }

    /**
     * The verbs that put package code back into the scheduler process, or run
     * it faster than an operator could ever correlate with load, are absent
     * rather than validated away.
     */
    public function testDisallowedSchedulingVerbsAreRefused(): void
    {
        foreach (['everySecond', 'everyTenSeconds', 'cron', 'then', 'onSuccess', 'onFailure', 'exec'] as $verb) {
            $task = $this->builder('p:ext:demo:sync')->command('p:ext:demo:sync');

            try {
                $task->{$verb}('* * * * *');
                $this->fail(sprintf('[%s] was accepted on an extension scheduled task.', $verb));
            } catch (DisplayException $exception) {
                $this->assertStringContainsString('not an allowed frequency', $exception->getMessage());
            }
        }
    }

    /**
     * Overlap protection is not optional. A schedule that piles up because one
     * run is slow is the standard way a background task takes a panel down.
     */
    public function testEveryEntryIsForcedWithoutOverlapping(): void
    {
        $schedule = app(Schedule::class);
        $before = count($schedule->events());

        (new ExtensionScheduleBuilder($schedule, 'demo', new ExtensionCapabilitySet(schedule: true, commands: ['p:ext:demo:sync'])))
            ->command('p:ext:demo:sync')
            ->daily();

        $events = $schedule->events();
        $this->assertCount($before + 1, $events);

        $event = end($events);
        $this->assertNotNull($event->expiresAt, 'withoutOverlapping() was not applied.');
        $this->assertTrue($event->onOneServer, 'onOneServer() was not applied.');
    }
}
