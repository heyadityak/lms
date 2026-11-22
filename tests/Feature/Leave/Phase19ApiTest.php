<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Enums\LedgerKind;
use App\Enums\Module;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Notifications\LeaveRequestEscalated;
use App\Notifications\LeaveRequestSubmitted;
use App\Support\Modules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 19 happy paths. Responses are checked against openapi.yaml where the path exists. */
final class Phase19ApiTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    public function test_owner_uploads_one_attachment_and_readers_can_download_it(): void
    {
        Storage::fake('s3');
        $id = $this->apply($this->alice, ['start_date' => '2026-12-14', 'end_date' => '2026-12-14'])->assertCreated()->json('data.id');
        $png = UploadedFile::fake()->createWithContent('note.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $created = $this->api($this->alice)->post("/api/v1/leave-requests/{$id}/attachments", ['file' => $png])->assertCreated()
            ->assertJsonPath('data.file_name', 'note.png')
            ->assertJsonPath('data.content_type', 'image/png');
        $this->assertMatchesSpec($created, '/leave-requests/{leaveRequestId}/attachments', 'post');
        $this->assertGreaterThan(0, $created->json('data.byte_size'));

        $download = $this->api($this->bob)->get("/api/v1/leave-requests/{$id}/attachments")->assertOk();
        $this->assertStringStartsWith("\x89PNG", $download->streamedContent());
        $this->api($this->carol)->get("/api/v1/leave-requests/{$id}/attachments")->assertOk();

        $this->api($this->alice)->delete("/api/v1/leave-requests/{$id}/attachments")->assertNoContent();
        $this->api($this->alice)->get("/api/v1/leave-requests/{$id}/attachments")->assertNotFound();
    }

    public function test_avatar_round_trip(): void
    {
        Storage::fake('s3');
        $png = UploadedFile::fake()->createWithContent('me.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $this->api($this->alice)->post('/api/v1/me/avatar', ['file' => $png])->assertNoContent();
        $me = $this->api($this->alice)->getJson('/api/v1/me')->assertOk();
        $this->assertMatchesSpec($me, '/me');
        $this->assertNotNull($me->json('data.avatar_url'));

        $image = $this->api($this->bob)->get('/api/v1/users/'.$this->alice->public_id.'/avatar')->assertOk();
        $this->assertStringStartsWith("\x89PNG", $image->streamedContent());

        $this->api($this->alice)->delete('/api/v1/me/avatar')->assertNoContent();
        $this->api($this->alice)->get('/api/v1/users/'.$this->alice->public_id.'/avatar')->assertNotFound();
        $this->api($this->alice)->delete('/api/v1/me/avatar')->assertNotFound();
    }

    public function test_pdf_downloads_for_the_owner(): void
    {
        $id = $this->apply($this->alice, ['start_date' => '2026-12-14', 'end_date' => '2026-12-14'])->assertCreated()->json('data.id');

        $pdf = $this->api($this->alice)->get("/api/v1/leave-requests/{$id}/pdf")->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->api($this->carol)->get("/api/v1/leave-requests/{$id}/pdf")->assertOk();
    }

    public function test_team_calendar_is_approved_leave_in_the_same_department(): void
    {
        $dept = Department::factory()->create();
        $peer = User::factory()->managedBy($this->bob)->create(['name' => 'Peer Person', 'department_id' => $dept->id]);
        $this->alice->forceFill(['department_id' => $dept->id])->save();
        $this->grant($peer, 'EL', 30);
        $outsider = User::factory()->managedBy($this->bob)->create(['department_id' => Department::factory()->create()->id]);
        $this->grant($outsider, 'EL', 30);

        $aliceId = $this->apply($this->alice, ['start_date' => '2026-12-14', 'end_date' => '2026-12-14'])->assertCreated()->json('data.id');
        $peerId = $this->apply($peer, ['start_date' => '2026-12-15', 'end_date' => '2026-12-15'])->assertCreated()->json('data.id');
        $otherId = $this->apply($outsider, ['start_date' => '2026-12-16', 'end_date' => '2026-12-16'])->assertCreated()->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$aliceId}/approve")->assertOk();
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$peerId}/approve")->assertOk();
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$otherId}/approve")->assertOk();
        $this->apply($this->alice, ['start_date' => '2026-12-17', 'end_date' => '2026-12-17'])->assertCreated();

        $calendar = $this->api($this->alice)->getJson('/api/v1/team-calendar?from=2026-12-14&to=2026-12-18')->assertOk();
        $this->assertMatchesSpec($calendar, '/team-calendar');
        $names = array_column($calendar->json('data'), 'user_name');
        $this->assertSame(['Alice Employee', 'Peer Person'], $names);
        $this->assertArrayNotHasKey('reason', $calendar->json('data.0'));

        $solo = User::factory()->create(['department_id' => null, 'manager_id' => $this->bob->id]);
        $this->grant($solo, 'EL', 30);
        $soloId = $this->apply($solo, ['start_date' => '2026-12-18', 'end_date' => '2026-12-18'])->assertCreated()->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$soloId}/approve")->assertOk();
        $own = $this->api($solo)->getJson('/api/v1/team-calendar?from=2026-12-14&to=2026-12-18')->assertOk();
        $this->assertSame([$solo->name], array_column($own->json('data'), 'user_name'));
    }

    public function test_hourly_leave_is_an_eighth_and_rejects_a_range_or_a_half_day(): void
    {
        $created = $this->apply($this->alice, [
            'start_date' => '2026-12-21',
            'end_date' => '2026-12-21',
            'day_part' => 'full',
            'hours' => 2,
        ])->assertCreated()->assertJsonPath('data.hours', 2)->assertJsonPath('data.days', 0.25);
        $this->assertMatchesSpec($created, '/leave-requests', 'post');

        $this->apply($this->alice, [
            'start_date' => '2026-12-22',
            'end_date' => '2026-12-23',
            'hours' => 4,
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->apply($this->alice, [
            'leave_type_id' => $this->type('CL')->public_id,
            'start_date' => '2026-12-22',
            'end_date' => '2026-12-22',
            'day_part' => 'first_half',
            'hours' => 2,
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_delegate_receives_the_approval_while_the_window_covers_today(): void
    {
        Notification::fake();
        $dave = User::factory()->manager()->create(['name' => 'Dave Delegate']);
        $created = $this->api($this->bob)->postJson('/api/v1/me/approval-delegations', [
            'delegate_id' => $dave->public_id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ])->assertCreated()->assertJsonPath('data.delegate.id', $dave->public_id);
        $this->assertMatchesSpec($created, '/me/approval-delegations', 'post');

        $id = $this->apply($this->alice, ['start_date' => '2026-12-14', 'end_date' => '2026-12-14'])->assertCreated()->json('data.id');
        Notification::assertSentTo($dave, LeaveRequestSubmitted::class);
        Notification::assertNotSentTo($this->bob, LeaveRequestSubmitted::class);
        $this->api($dave)->getJson('/api/v1/approvals')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->api($this->bob)->getJson('/api/v1/approvals')->assertOk()->assertJsonMissing(['id' => $id]);

        $this->api($this->bob)->postJson('/api/v1/me/approval-delegations', [
            'delegate_id' => $this->carol->public_id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-15',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->api($this->bob)->delete('/api/v1/me/approval-delegations')->assertNoContent();
        $this->api($this->bob)->getJson('/api/v1/me/approval-delegations')->assertNotFound();
    }

    public function test_multi_level_sends_long_requests_to_hr(): void
    {
        config(['modules.licensed' => ['holidays', 'reports', 'api', 'notifications', 'user_management', 'multi_level']]);
        config(['lms.hr_approval_days' => 5]);
        $this->app->forgetScopedInstances();
        $this->assertTrue(app(Modules::class)->enabled(Module::MultiLevel));

        $id = $this->apply($this->alice, ['start_date' => '2026-12-14', 'end_date' => '2026-12-18'])->assertCreated()->assertJsonPath('data.days', 5)->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve")->assertForbidden();
        $this->api($this->bob)->getJson('/api/v1/approvals')->assertOk()->assertJsonMissing(['id' => $id]);
        $this->api($this->carol)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_stale_pending_requests_escalate_once(): void
    {
        Notification::fake();
        $request = LeaveRequest::factory()->for($this->alice)->for($this->type('EL'))->create();
        $this->travelTo('2026-11-20 09:00:00');

        $this->artisan('lms:escalate-stale-approvals')->assertSuccessful();
        $this->assertNotNull($request->refresh()->escalated_at);
        Notification::assertSentToTimes($this->carol, LeaveRequestEscalated::class, 1);

        $this->artisan('lms:escalate-stale-approvals')->assertSuccessful();
        Notification::assertSentToTimes($this->carol, LeaveRequestEscalated::class, 1);

        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'lms:escalate-stale-approvals'));
        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_escalation_writes_nothing_when_notifications_are_off(): void
    {
        Notification::fake();
        $request = LeaveRequest::factory()->for($this->alice)->for($this->type('EL'))->create();
        $this->setting('modules.disabled', ['notifications']);
        $this->travelTo('2026-11-20 09:00:00');

        $this->artisan('lms:escalate-stale-approvals')->assertSuccessful();
        $this->assertNull($request->refresh()->escalated_at);
        Notification::assertNothingSent();
    }

    public function test_department_quota_is_what_the_next_grant_uses(): void
    {
        $dept = Department::factory()->create();
        $saved = $this->api($this->carol)->putJson("/api/v1/departments/{$dept->public_id}/leave-quotas", [
            'leave_type_id' => $this->type('EL')->public_id,
            'annual_quota' => 6,
        ])->assertOk()->assertJsonPath('data.annual_quota', 6);
        $this->assertMatchesSpec($saved, '/departments/{departmentId}/leave-quotas', 'put');

        $joiner = User::factory()->managedBy($this->bob)->create(['department_id' => $dept->id, 'date_of_joining' => null]);
        $this->api($this->carol)->patchJson('/api/v1/users/'.$joiner->public_id, ['date_of_joining' => '2026-01-15'])->assertOk();
        $grant = LeaveLedgerEntry::query()->where(['user_id' => $joiner->id, 'leave_type_id' => $this->type('EL')->id, 'kind' => LedgerKind::Grant])->first();
        $this->assertEquals(6.0, $grant->days);
        $this->assertTrue(AuditLog::query()->where('subject_type', 'leave_quota')->exists());
        $this->assertTrue(AuditLog::query()->where('subject_type', 'user')->where('subject_id', $joiner->public_id)->exists());
    }

    public function test_encashment_debits_available_balance(): void
    {
        $created = $this->api($this->carol)->postJson('/api/v1/users/'.$this->alice->public_id.'/encashments', [
            'leave_type_id' => $this->type('EL')->public_id,
            'days' => 2,
            'note' => 'Year-end encashment',
        ])->assertCreated()->assertJsonPath('data.kind', 'encashment')->assertJsonPath('data.days', -2);
        $this->assertMatchesSpec($created, '/users/{userId}/encashments', 'post');
        $this->assertSame(-2.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Encashment));

        $this->api($this->carol)->postJson('/api/v1/users/'.$this->alice->public_id.'/encashments', [
            'leave_type_id' => $this->type('EL')->public_id,
            'days' => 100,
            'note' => 'Too much',
        ])->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $this->assertTrue(AuditLog::query()->where('subject_type', 'encashment')->exists());
    }

    public function test_sandwich_counts_the_weekend(): void
    {
        $this->apply($this->alice, ['start_date' => '2026-12-26', 'end_date' => '2026-12-27'])->assertUnprocessable()->assertJsonPath('code', 'NO_WORKING_DAYS');

        $updated = $this->api($this->carol)->patchJson('/api/v1/leave-types/'.$this->type('EL')->public_id, ['sandwich' => true])->assertOk()
            ->assertJsonPath('data.sandwich', true);
        $this->assertMatchesSpec($updated, '/leave-types/{leaveTypeId}', 'patch');

        $this->apply($this->alice, ['start_date' => '2026-12-26', 'end_date' => '2026-12-27'])->assertCreated()->assertJsonPath('data.days', 2);
        $this->assertTrue(AuditLog::query()->where('subject_type', 'leave_type')->exists());
    }

    public function test_monthly_accrual_grants_one_twelfth_and_skips_yearly_types(): void
    {
        $this->api($this->carol)->patchJson('/api/v1/leave-types/'.$this->type('CL')->public_id, ['accrual_frequency' => 'monthly'])->assertOk();

        $this->artisan('lms:accrue-month')->assertSuccessful();
        $grants = LeaveLedgerEntry::query()->where('accrual_month', 11)->where('kind', LedgerKind::Grant)->get();
        $this->assertCount(3, $grants);
        $this->assertEquals(0.833, (float) $grants->first()->days);
        $this->assertSame(0, LeaveLedgerEntry::query()->where('accrual_month', 11)->where('leave_type_id', $this->type('EL')->id)->count());

        $this->artisan('lms:accrue-month')->assertSuccessful();
        $this->assertCount(3, LeaveLedgerEntry::query()->where('accrual_month', 11)->where('kind', LedgerKind::Grant)->get());

        $monthly = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'lms:accrue-month'));
        $this->assertNotNull($monthly);
        $this->assertSame('15 0 1 * *', $monthly->expression);
    }

    public function test_location_holiday_applies_only_to_that_location(): void
    {
        $hq = $this->api($this->carol)->postJson('/api/v1/locations', ['code' => 'HQ', 'name' => 'Headquarters'])->assertCreated();
        $this->assertMatchesSpec($hq, '/locations', 'post');
        $branch = $this->api($this->carol)->postJson('/api/v1/locations', ['code' => 'BR', 'name' => 'Branch'])->assertCreated()->json('data.id');
        $hqId = $hq->json('data.id');

        $this->api($this->carol)->patchJson('/api/v1/users/'.$this->alice->public_id, ['location_id' => $hqId])->assertOk()
            ->assertJsonPath('data.location.code', 'HQ');

        $this->api($this->carol)->postJson('/api/v1/holidays', [
            'date' => '2026-12-22',
            'name' => 'Branch day',
            'location_id' => $branch,
        ])->assertCreated();
        $this->apply($this->alice, ['start_date' => '2026-12-22', 'end_date' => '2026-12-22'])->assertCreated()->assertJsonPath('data.days', 1);

        $global = $this->api($this->carol)->postJson('/api/v1/holidays', [
            'date' => '2026-12-23',
            'name' => 'Company day',
        ])->assertCreated();
        $this->assertMatchesSpec($global, '/holidays', 'post');
        $this->assertNull($global->json('data.location'));
        $this->apply($this->alice, ['start_date' => '2026-12-23', 'end_date' => '2026-12-23'])->assertUnprocessable()->assertJsonPath('code', 'NO_WORKING_DAYS');

        Holiday::query()->create(['date' => '2026-12-24', 'name' => 'HQ day', 'location_id' => Location::query()->where('code', 'HQ')->value('id')]);
        $this->apply($this->alice, ['start_date' => '2026-12-24', 'end_date' => '2026-12-24'])->assertUnprocessable()->assertJsonPath('code', 'NO_WORKING_DAYS');
    }

    public function test_hr_can_read_the_audit_log(): void
    {
        $this->api($this->carol)->postJson('/api/v1/departments', ['code' => 'FIN', 'name' => 'Finance'])->assertCreated();

        $page = $this->api($this->carol)->getJson('/api/v1/audit-logs')->assertOk();
        $this->assertMatchesSpec($page, '/audit-logs');
        $this->assertSame('department', $page->json('data.0.subject_type'));
        $this->assertSame('created', $page->json('data.0.action'));
        $this->api($this->alice)->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
