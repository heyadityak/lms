<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 6 core flow over /api/v1, every response validated against openapi.yaml. */
final class LeaveApiTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
        Holiday::query()->create(['date' => '2026-12-25', 'name' => 'Christmas Day']);
    }

    private function assertProblem(TestResponse $response, int $status, string $code, string $path, string $method = 'post'): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, $path, $method);
    }

    // UC-E9 / E4: me, balances, catalogue

    public function test_me_returns_user_resource_with_ulid(): void
    {
        $response = $this->api($this->alice)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $this->alice->public_id)
            ->assertJsonPath('data.manager.id', $this->bob->public_id)
            ->assertJsonPath('data.role', 'employee');
        $this->assertMatchesSpec($response, '/me');
        $this->assertNotNull($this->alice->refresh()->last_login_at);
    }

    public function test_leave_types_and_holidays_lists(): void
    {
        $types = $this->api($this->alice)->getJson('/api/v1/leave-types')->assertOk()->assertJsonCount(4, 'data');
        $this->assertMatchesSpec($types, '/leave-types');

        $holidays = $this->api($this->alice)->getJson('/api/v1/holidays?filter[year]=2026')->assertOk()->assertJsonPath('data.0.date', '2026-12-25');
        $this->assertMatchesSpec($holidays, '/holidays');

        $this->setting('modules.disabled', ['holidays']);
        $this->assertProblem($this->api($this->alice)->getJson('/api/v1/holidays'), 404, 'NOT_FOUND', '/holidays', 'get');
    }

    // UC-E5 submit + UC-M2 approve + balances

    public function test_submit_then_manager_approves_and_balance_is_debited(): void
    {
        $created = $this->apply($this->alice)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days', 4) // 21-25 Dec minus Christmas
            ->assertJsonPath('data.leave_year', 2026)
            ->assertHeader('Location');
        $this->assertMatchesSpec($created, '/leave-requests', 'post');
        $id = $created->json('data.id');
        $this->assertDatabaseHas('leave_request_actions', ['action' => 'submitted', 'to_status' => 'pending', 'actor_id' => $this->alice->id]);

        $balances = $this->api($this->alice)->getJson('/api/v1/me/balances')->assertOk();
        $this->assertMatchesSpec($balances, '/me/balances');
        $el = collect($balances->json('data'))->firstWhere('leave_type.code', 'EL');
        $this->assertEquals(['entitled' => 30, 'used' => 0, 'pending' => 4, 'balance' => 30, 'available' => 26], array_intersect_key($el, array_flip(['entitled', 'used', 'pending', 'balance', 'available'])));

        $inbox = $this->api($this->bob)->getJson('/api/v1/approvals')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertMatchesSpec($inbox, '/approvals');

        $approved = $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve", ['comment' => 'Enjoy!'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', $this->bob->public_id)
            ->assertJsonPath('data.actions.1.action', 'approved');
        $this->assertMatchesSpec($approved, '/leave-requests/{leaveRequestId}/approve', 'post');
        $this->assertSame(-4.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Debit));

        $el = collect($this->api($this->alice)->getJson('/api/v1/me/balances')->json('data'))->firstWhere('leave_type.code', 'EL');
        $this->assertEquals([30, 4, 0, 26, 26], [$el['entitled'], $el['used'], $el['pending'], $el['balance'], $el['available']]);
    }

    public function test_double_approve_is_409(): void
    {
        $id = $this->apply($this->alice)->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();
        $this->assertProblem($this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve"), 409, 'INVALID_TRANSITION', '/leave-requests/{leaveRequestId}/approve');
        $this->assertSame(1, LeaveRequest::query()->firstOrFail()->ledgerEntries()->count());
    }

    public function test_self_approval_and_non_report_are_forbidden_hr_can_approve(): void
    {
        User::factory()->hrAdmin()->create(); // carol has no manager: another hr_admin is her approver
        $own = $this->apply($this->carol)->assertCreated()->json('data.id');
        $this->assertProblem($this->api($this->carol)->postJson("/api/v1/leave-requests/{$own}/approve"), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/approve');

        $id = $this->apply($this->alice)->json('data.id');
        $otherManager = User::factory()->manager()->create();
        $this->assertProblem($this->api($otherManager)->postJson("/api/v1/leave-requests/{$id}/approve"), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/approve');
        $this->assertProblem($this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/approve"), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/approve');
        $this->api($this->carol)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();
    }

    // UC-M3 reject

    public function test_reject_requires_comment(): void
    {
        $id = $this->apply($this->alice)->json('data.id');
        $this->assertProblem($this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/reject", []), 422, 'COMMENT_REQUIRED', '/leave-requests/{leaveRequestId}/reject');

        $rejected = $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/reject", ['comment' => 'Release week'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_comment', 'Release week');
        $this->assertMatchesSpec($rejected, '/leave-requests/{leaveRequestId}/reject', 'post');
        $this->assertSame(0.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Debit));
    }

    // UC-E7 / UC-H6 cancel

    public function test_cancel_pending_and_approved_future_leave_refunds_ledger(): void
    {
        $pending = $this->apply($this->alice, ['start_date' => '2026-11-30', 'end_date' => '2026-11-30'])->json('data.id');
        $cancelled = $this->api($this->alice)->postJson("/api/v1/leave-requests/{$pending}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertMatchesSpec($cancelled, '/leave-requests/{leaveRequestId}/cancel', 'post');

        $id = $this->apply($this->alice)->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();
        $this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/cancel", ['comment' => 'Trip off'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(4.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Reversal));
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
        $this->assertProblem($this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/cancel"), 409, 'INVALID_TRANSITION', '/leave-requests/{leaveRequestId}/cancel');
    }

    public function test_started_leave_only_hr_can_cancel(): void
    {
        $id = $this->apply($this->alice)->json('data.id');
        $this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();
        $this->travelTo('2026-12-22 09:00:00');

        $this->assertProblem($this->api($this->alice)->postJson("/api/v1/leave-requests/{$id}/cancel"), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/cancel');
        $this->assertProblem($this->api($this->bob)->postJson("/api/v1/leave-requests/{$id}/cancel"), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/cancel');
        $this->api($this->carol)->postJson("/api/v1/leave-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
    }

    // Submission rules V1–V8

    public function test_overlap_is_rejected_by_pre_check(): void
    {
        $this->apply($this->alice)->assertCreated();
        $this->assertProblem($this->apply($this->alice, ['start_date' => '2026-12-24', 'end_date' => '2026-12-28']), 422, 'LEAVE_OVERLAP', '/leave-requests');
        // Half days on the same date also overlap (MVP).
        $this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'day_part' => 'first_half'])->assertCreated();
        $this->assertProblem($this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'day_part' => 'second_half']), 422, 'LEAVE_OVERLAP', '/leave-requests');
    }

    public function test_overlap_race_is_caught_by_exclusion_constraint(): void
    {
        // Simulate a concurrent insert landing between the pre-check and our INSERT.
        $raced = false;
        LeaveRequest::creating(function () use (&$raced): void {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('leave_requests')->insert([
                'public_id' => strtoupper((string) Str::ulid()), 'user_id' => $this->alice->id, 'leave_type_id' => $this->type('EL')->id,
                'start_date' => '2026-12-22', 'end_date' => '2026-12-22', 'day_part' => 'full', 'days' => 1, 'status' => 'pending',
                'reason' => 'race', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertProblem($this->apply($this->alice), 422, 'LEAVE_OVERLAP', '/leave-requests');
        $this->assertSame(0, LeaveRequest::query()->count()); // whole transaction rolled back
    }

    public function test_insufficient_balance_rejected_lwp_exempt(): void
    {
        $this->assertProblem($this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-11-30', 'end_date' => '2026-12-14']), 422, 'INSUFFICIENT_BALANCE', '/leave-requests');
        // Pending requests reserve balance: 8 + 8 CL > 10.
        $this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-11-30', 'end_date' => '2026-12-09'])->assertCreated()->assertJsonPath('data.days', 8);
        $this->assertProblem($this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-12-14', 'end_date' => '2026-12-16']), 422, 'INSUFFICIENT_BALANCE', '/leave-requests');

        $this->apply($this->alice, ['leave_type_id' => $this->type('LWP')->public_id, 'start_date' => '2027-01-04', 'end_date' => '2027-01-29'])->assertCreated()->assertJsonPath('data.days', 20);
    }

    public function test_weekend_holiday_and_working_week_override(): void
    {
        $this->assertProblem($this->apply($this->alice, ['start_date' => '2026-12-26', 'end_date' => '2026-12-27']), 422, 'NO_WORKING_DAYS', '/leave-requests');
        $this->assertProblem($this->apply($this->alice, ['start_date' => '2026-12-25', 'end_date' => '2026-12-25']), 422, 'NO_WORKING_DAYS', '/leave-requests');

        // holidays module off ⇒ holidays are not excluded.
        $this->setting('modules.disabled', ['holidays']);
        $this->apply($this->alice, ['start_date' => '2026-12-25', 'end_date' => '2026-12-25'])->assertCreated()->assertJsonPath('data.days', 1);

        // Fri/Sat weekend (D-30): Mon 30 Nov … Sun 6 Dec = 5 working days (Sun counts).
        $this->setting('work.weekend_days', [5, 6]);
        $this->apply($this->alice, ['start_date' => '2026-11-30', 'end_date' => '2026-12-06'])->assertCreated()->assertJsonPath('data.days', 5);
    }

    public function test_half_day_rules(): void
    {
        $this->assertProblem($this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'day_part' => 'first_half']), 422, 'HALF_DAY_NOT_ALLOWED', '/leave-requests');
        $this->assertProblem($this->apply($this->alice, ['start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'day_part' => 'first_half']), 422, 'HALF_DAY_NOT_ALLOWED', '/leave-requests'); // EL: full days only
        $this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'day_part' => 'second_half'])->assertCreated()->assertJsonPath('data.days', 0.5);
    }

    public function test_leave_year_boundary_with_april_start(): void
    {
        config(['lms.leave_year_start_month' => 4]);
        $this->grant($this->alice, 'CL', 10, 2027);

        $this->assertProblem($this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2027-03-30', 'end_date' => '2027-04-02']), 422, 'INVALID_DATE_RANGE', '/leave-requests');
        $this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2027-04-01', 'end_date' => '2027-04-02'])->assertCreated()->assertJsonPath('data.leave_year', 2027);

        $balances = $this->api($this->alice)->getJson('/api/v1/me/balances')->assertOk()
            ->assertJsonPath('meta.year', 2026)->assertJsonPath('meta.leave_year_start', '2026-04-01')->assertJsonPath('meta.leave_year_end', '2027-03-31');
        $this->assertMatchesSpec($balances, '/me/balances');
        $next = collect($this->api($this->alice)->getJson('/api/v1/me/balances?filter[year]=2027')->json('data'))->firstWhere('leave_type.code', 'CL');
        $this->assertEquals([10, 2, 8], [$next['balance'], $next['pending'], $next['available']]);
    }

    public function test_backdate_inactive_type_and_date_order(): void
    {
        $this->assertProblem($this->apply($this->alice, ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-11-13', 'end_date' => '2026-11-13']), 422, 'BACKDATE_LIMIT_EXCEEDED', '/leave-requests');
        $this->apply($this->alice, ['leave_type_id' => $this->type('SL')->public_id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-10'])->assertCreated(); // SL: 7 days back
        $this->assertProblem($this->apply($this->alice, ['end_date' => '2026-12-20']), 422, 'INVALID_DATE_RANGE', '/leave-requests');

        $this->type('EL')->update(['is_active' => false]);
        $this->assertProblem($this->apply($this->alice), 422, 'LEAVE_TYPE_INACTIVE', '/leave-requests');
        $this->assertProblem($this->apply($this->alice, ['reason' => 'x']), 422, 'VALIDATION_FAILED', '/leave-requests');
    }

    public function test_no_approver_for_sole_hr_admin_without_manager(): void
    {
        $this->assertProblem($this->apply($this->carol), 422, 'NO_APPROVER', '/leave-requests');
    }

    // UC-E6 / UC-M4 lists and detail

    public function test_lists_detail_filters_and_visibility(): void
    {
        $id = $this->apply($this->alice)->json('data.id');
        $this->apply($this->bob, ['start_date' => '2026-12-01', 'end_date' => '2026-12-01'])->assertCreated();

        $mine = $this->api($this->alice)->getJson('/api/v1/me/leave-requests?filter[status]=pending&filter[year]=2026&page[size]=10')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 10);
        $this->assertMatchesSpec($mine, '/me/leave-requests');

        $team = $this->api($this->bob)->getJson('/api/v1/leave-requests?sort=start_date&include=actions')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.start_date', '2026-12-01');
        $this->assertMatchesSpec($team, '/leave-requests');
        $this->api($this->alice)->getJson('/api/v1/leave-requests')->assertJsonCount(1, 'data');
        $this->api($this->carol)->getJson('/api/v1/leave-requests?filter[user]='.$this->alice->public_id)->assertJsonCount(1, 'data');

        $show = $this->api($this->bob)->getJson("/api/v1/leave-requests/{$id}?include=actions")->assertOk()->assertJsonPath('data.actions.0.action', 'submitted');
        $this->assertMatchesSpec($show, '/leave-requests/{leaveRequestId}');
        $stranger = User::factory()->create();
        $this->assertProblem($this->api($stranger)->getJson("/api/v1/leave-requests/{$id}"), 404, 'NOT_FOUND', '/leave-requests/{leaveRequestId}', 'get');

        $this->assertProblem($this->api($this->alice)->getJson('/api/v1/leave-requests?filter[bogus]=1'), 422, 'VALIDATION_FAILED', '/leave-requests', 'get');
        $this->assertProblem($this->api($this->alice)->getJson('/api/v1/leave-requests?page[size]=101'), 422, 'VALIDATION_FAILED', '/leave-requests', 'get');
        $this->assertProblem($this->api($this->alice)->getJson('/api/v1/approvals'), 403, 'FORBIDDEN', '/approvals', 'get');
    }

    public function test_idempotent_submit_replays(): void
    {
        $key = (string) Str::uuid();
        $body = ['leave_type_id' => $this->type('EL')->public_id, 'start_date' => '2026-12-21', 'end_date' => '2026-12-25', 'day_part' => 'full', 'reason' => 'Trip'];
        $first = $this->api($this->alice)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key])->assertCreated();
        $replay = $this->api($this->alice)->postJson('/api/v1/leave-requests', $body, ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, LeaveRequest::query()->where('status', LeaveStatus::Pending)->count());
    }

    // §3.7 / first login

    public function test_first_login_with_joining_date_claim_grants_prorated_entitlement(): void
    {
        $this->withToken($this->jwt(['sub' => 'sub-new', 'email' => 'new@example.test', 'name' => 'New Joiner', 'date_of_joining' => '2026-07-01'], ['employee']))
            ->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.date_of_joining', '2026-07-01');

        $user = User::query()->where('keycloak_sub', 'sub-new')->firstOrFail();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame([5.0, 7.5, 15.0], [$this->ledgerSum($user, 'CL'), $this->ledgerSum($user, 'SL'), $this->ledgerSum($user, 'EL')]);

        // Second call: no duplicate grant.
        $this->withToken($this->jwt(['sub' => 'sub-new', 'email' => 'new@example.test', 'name' => 'New Joiner', 'date_of_joining' => '2026-07-01'], ['employee']))->getJson('/api/v1/me')->assertOk();
        $this->assertSame(3, $user->ledgerEntries()->count());
    }
}
