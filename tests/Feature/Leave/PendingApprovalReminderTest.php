<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Models\ApprovalReminder;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\PendingApprovalsReminder;
use App\Support\Brand;
use App\Support\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** UC-S4: one digest per approver per calendar day, same rows as the approvals inbox. */
final class PendingApprovalReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_with_a_pending_request_is_reminded_once_per_day(): void
    {
        Notification::fake();
        $this->travelTo('2026-11-22 08:00:00');

        $manager = User::factory()->manager()->create(['name' => 'Bob Manager']);
        $employee = User::factory()->managedBy($manager)->create(['name' => 'Alice Employee']);
        $hr = User::factory()->hrAdmin()->create(['name' => 'Carol HR']);
        LeaveRequest::factory()->for($employee)->create();

        $this->artisan('lms:remind-pending-approvals')
            ->expectsOutputToContain('2 queued, 0 nothingPending, 0 alreadyReminded')
            ->assertSuccessful();

        Notification::assertSentToTimes($manager, PendingApprovalsReminder::class, 1);
        Notification::assertSentToTimes($hr, PendingApprovalsReminder::class, 1);
        Notification::assertNotSentTo($employee, PendingApprovalsReminder::class);
        $this->assertSame(2, ApprovalReminder::query()->count());

        $brand = Brand::fromSettings(app(Settings::class));
        $expected = rtrim((string) config('app.url'), '/').'/approvals';
        Notification::assertSentTo($manager, PendingApprovalsReminder::class, function (PendingApprovalsReminder $notification) use ($manager, $brand, $expected): bool {
            $this->assertSame(1, $notification->pendingCount);
            $mail = $notification->toMail($manager);
            $this->assertSame("[{$brand->name}] 1 leave request awaiting your decision", $mail->subject);
            $this->assertSame($expected, $mail->viewData['url']);
            $this->assertSame(['1 leave request is waiting in your approvals inbox.'], $mail->viewData['lines']);
            $this->assertSame('Open approvals', $mail->viewData['action']);
            $this->assertStringContainsString('href="'.$expected.'"', view('mail.leave-request', $mail->viewData)->render());
            $this->assertStringContainsString($expected, view('mail.leave-request-text', $mail->viewData)->render());

            return true;
        });
        Notification::assertSentTo($hr, PendingApprovalsReminder::class, function (PendingApprovalsReminder $notification): bool {
            $this->assertSame(1, $notification->pendingCount);

            return true;
        });

        $this->artisan('lms:remind-pending-approvals')
            ->expectsOutputToContain('0 queued, 0 nothingPending, 2 alreadyReminded')
            ->assertSuccessful();
        Notification::assertSentToTimes($manager, PendingApprovalsReminder::class, 1);
        Notification::assertSentToTimes($hr, PendingApprovalsReminder::class, 1);
        $this->assertSame(2, ApprovalReminder::query()->count());
    }

    public function test_notifications_module_off_sends_nothing(): void
    {
        Notification::fake();
        $manager = User::factory()->manager()->create();
        $employee = User::factory()->managedBy($manager)->create();
        LeaveRequest::factory()->for($employee)->create();
        app(Settings::class)->set('modules.disabled', ['notifications']);
        $this->app->forgetScopedInstances();

        $this->artisan('lms:remind-pending-approvals')
            ->expectsOutputToContain('Notifications module is off')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, ApprovalReminder::query()->count());
    }

    public function test_command_is_scheduled_at_0800_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'lms:remind-pending-approvals'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }
}
