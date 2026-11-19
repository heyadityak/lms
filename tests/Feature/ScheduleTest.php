<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** Scheduler runs on N replicas (D-21), so every task must be single-run and non-overlapping. */
final class ScheduleTest extends TestCase
{
    public function test_every_scheduled_task_is_on_one_server_and_without_overlapping(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertTrue($event->onOneServer, "{$event->getSummaryForDisplay()} must use ->onOneServer()");
            $this->assertTrue($event->withoutOverlapping, "{$event->getSummaryForDisplay()} must use ->withoutOverlapping()");
        }

        $this->addToAssertionCount(1);
    }
}
