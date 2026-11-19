<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Actions\Leave\ApproveLeaveRequest;
use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Phase 7 QA: business-rule edges (V1–V8), the full state machine, and RBAC over /api/v1.
 * Fixture (LeaveTestHelpers): today = Mon 2026-11-16, alice → bob, carol = only hr_admin, Christmas holiday.
 */
final class LeaveRulesEdgeTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
        Holiday::query()->create(['date' => '2026-12-25', 'name' => 'Christmas Day']);
    }

    private function problem(TestResponse $response, int $status, string $code, string $path, string $method = 'post'): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, $path, $method);
    }

    /** @param  array<string, mixed>  $overrides */
    private function applyType(User $user, string $code, string $start, ?string $end = null, string $part = 'full', array $overrides = []): TestResponse
    {
        return $this->apply($user, ['leave_type_id' => $this->type($code)->public_id, 'start_date' => $start, 'end_date' => $end ?? $start, 'day_part' => $part] + $overrides);
    }

    private function act(User $actor, string $id, string $action, ?string $comment = null): TestResponse
    {
        return $this->api($actor)->postJson("/api/v1/leave-requests/{$id}/{$action}", $comment === null ? [] : ['comment' => $comment]);
    }

    // V6 overlap

    public function test_full_day_overlapping_a_half_day_is_rejected_adjacent_dates_are_not(): void
    {
        $this->applyType($this->alice, 'CL', '2026-12-01', part: 'first_half')->assertCreated();
        $this->problem($this->applyType($this->alice, 'EL', '2026-11-30', '2026-12-02'), 422, 'LEAVE_OVERLAP', '/leave-requests');
        $this->problem($this->applyType($this->alice, 'CL', '2026-12-01'), 422, 'LEAVE_OVERLAP', '/leave-requests');
        $this->applyType($this->alice, 'CL', '2026-12-02', part: 'second_half')->assertCreated();
        $this->applyType($this->alice, 'EL', '2026-12-03', '2026-12-04')->assertCreated();
    }

    public function test_rejected_and_cancelled_requests_free_their_dates(): void
    {
        $rejected = $this->applyType($this->alice, 'CL', '2026-12-01')->json('data.id');
        $this->act($this->bob, $rejected, 'reject', 'Release day')->assertOk();
        $cancelled = $this->applyType($this->alice, 'CL', '2026-12-01')->assertCreated()->json('data.id');
        $this->act($this->alice, $cancelled, 'cancel')->assertOk();
        $this->applyType($this->alice, 'CL', '2026-12-01')->assertCreated();
    }

    // V5 weekends / holidays

    public function test_weekend_and_holiday_only_ranges_and_half_days_on_them(): void
    {
        $this->problem($this->applyType($this->alice, 'CL', '2026-11-21', '2026-11-22'), 422, 'NO_WORKING_DAYS', '/leave-requests');
        // Holiday Fri + weekend = nothing to take.
        $this->problem($this->applyType($this->alice, 'EL', '2026-12-25', '2026-12-27'), 422, 'NO_WORKING_DAYS', '/leave-requests');
        $this->problem($this->applyType($this->alice, 'CL', '2026-12-25', part: 'first_half'), 422, 'NO_WORKING_DAYS', '/leave-requests');
        $this->problem($this->applyType($this->alice, 'CL', '2026-11-21', part: 'second_half'), 422, 'NO_WORKING_DAYS', '/leave-requests');

        $this->setting('modules.disabled', ['holidays']);
        $this->applyType($this->alice, 'CL', '2026-12-25', part: 'first_half')->assertCreated()->assertJsonPath('data.days', 0.5);
    }

    public function test_holiday_mid_range_only_counts_when_module_on(): void
    {
        $this->applyType($this->alice, 'EL', '2026-12-21', '2026-12-27')->assertCreated()->assertJsonPath('data.days', 4);
        $this->setting('modules.disabled', ['holidays']);
        $this->applyType($this->bob, 'EL', '2026-12-21', '2026-12-27')->assertCreated()->assertJsonPath('data.days', 5);
    }

    // V2 backdate boundary (SL 7 days, CL 0 days)

    public function test_backdate_limit_is_inclusive_at_the_boundary(): void
    {
        $this->problem($this->applyType($this->alice, 'SL', '2026-11-08', '2026-11-10'), 422, 'BACKDATE_LIMIT_EXCEEDED', '/leave-requests'); // 8 days back
        $this->applyType($this->alice, 'SL', '2026-11-09', '2026-11-10')->assertCreated()->assertJsonPath('data.days', 2); // exactly 7

        $this->problem($this->applyType($this->alice, 'CL', '2026-11-15'), 422, 'BACKDATE_LIMIT_EXCEEDED', '/leave-requests'); // yesterday
        $this->applyType($this->alice, 'CL', '2026-11-16')->assertCreated(); // today
    }

    // V3 leave-year boundaries (D-26)

    public function test_calendar_year_boundary(): void
    {
        $this->problem($this->applyType($this->alice, 'LWP', '2026-12-31', '2027-01-01'), 422, 'INVALID_DATE_RANGE', '/leave-requests');
        $this->applyType($this->alice, 'LWP', '2027-01-01')->assertCreated()->assertJsonPath('data.leave_year', 2027);
    }

    public function test_april_leave_year_spans_balances_and_filters(): void
    {
        config(['lms.leave_year_start_month' => 4]);
        $this->grant($this->alice, 'CL', 10, 2027);

        $this->problem($this->applyType($this->alice, 'CL', '2027-03-31', '2027-04-01'), 422, 'INVALID_DATE_RANGE', '/leave-requests');
        $march = $this->applyType($this->alice, 'CL', '2027-03-31')->assertCreated()->assertJsonPath('data.leave_year', 2026)->json('data.id');
        $this->applyType($this->alice, 'CL', '2027-04-01')->assertCreated()->assertJsonPath('data.leave_year', 2027);

        // Fiscal 2026 has 10 CL: 1 pending (31 Mar) + 10 more (4–15 Jan 2027) does not fit; 9 does.
        $this->problem($this->applyType($this->alice, 'CL', '2027-01-04', '2027-01-15'), 422, 'INSUFFICIENT_BALANCE', '/leave-requests');
        $this->applyType($this->alice, 'CL', '2027-01-04', '2027-01-14')->assertCreated()->assertJsonPath('data.days', 9);

        $list = $this->api($this->alice)->getJson('/api/v1/me/leave-requests?filter[year]=2026')->assertOk()->assertJsonCount(2, 'data');
        $this->assertMatchesSpec($list, '/me/leave-requests');
        $this->assertNotContains('2027-04-01', array_column($list->json('data'), 'start_date'));

        $this->act($this->bob, $march, 'approve')->assertOk();
        $this->assertSame(2026, LeaveLedgerEntry::query()->where('kind', LedgerKind::Debit)->sole()->year);
    }

    // V7 balance

    public function test_approve_rechecks_balance_against_other_pending_requests(): void
    {
        $first = $this->applyType($this->alice, 'CL', '2026-11-30', '2026-12-04')->assertJsonPath('data.days', 5)->json('data.id');
        $second = $this->applyType($this->alice, 'CL', '2026-12-07', '2026-12-11')->assertJsonPath('data.days', 5)->json('data.id');
        LeaveLedgerEntry::query()->create(['user_id' => $this->alice->id, 'leave_type_id' => $this->type('CL')->id, 'year' => 2026, 'kind' => LedgerKind::Adjustment, 'days' => -2, 'actor_id' => $this->carol->id, 'note' => 'Correction']);

        // 8 left, 5 reserved by the other pending request.
        $this->problem($this->act($this->bob, $first, 'approve'), 422, 'INSUFFICIENT_BALANCE', '/leave-requests/{leaveRequestId}/approve');
        $this->assertSame(LeaveStatus::Pending, LeaveRequest::query()->where('public_id', $first)->sole()->status);
        $this->assertSame(0.0, $this->ledgerSum($this->alice, 'CL', LedgerKind::Debit));

        $this->act($this->bob, $second, 'reject', 'Too long')->assertOk();
        $this->act($this->bob, $first, 'approve')->assertOk();
        $this->assertSame(3.0, $this->ledgerSum($this->alice, 'CL'));
    }

    public function test_lwp_needs_no_balance_and_never_touches_the_ledger(): void
    {
        $id = $this->applyType($this->alice, 'LWP', '2026-12-01', '2026-12-04')->assertCreated()->assertJsonPath('data.days', 4)->json('data.id');
        $this->act($this->bob, $id, 'approve')->assertOk();
        $this->act($this->alice, $id, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, LeaveLedgerEntry::query()->where('leave_type_id', $this->type('LWP')->id)->count());

        $balances = $this->api($this->alice)->getJson('/api/v1/me/balances')->assertOk();
        $this->assertNotContains('LWP', array_column(array_column($balances->json('data'), 'leave_type'), 'code'));
    }

    public function test_inactive_type_hidden_from_employees_listed_for_hr(): void
    {
        $this->type('CL')->update(['is_active' => false]);
        $codes = fn (TestResponse $r): array => array_column($r->assertOk()->json('data'), 'code');

        $this->assertNotContains('CL', $codes($this->api($this->alice)->getJson('/api/v1/leave-types')));
        $this->assertNotContains('CL', $codes($this->api($this->alice)->getJson('/api/v1/leave-types?filter[is_active]=false')));
        $inactive = $this->api($this->carol)->getJson('/api/v1/leave-types?filter[is_active]=false');
        $this->assertSame(['CL'], $codes($inactive));
        $this->assertMatchesSpec($inactive, '/leave-types');
        $this->problem($this->applyType($this->alice, 'CL', '2026-12-01'), 422, 'LEAVE_TYPE_INACTIVE', '/leave-requests');
    }

    // State machine (USE_CASES §3.6): anything else → 409

    public function test_every_invalid_transition_is_409_and_writes_nothing(): void
    {
        $approved = $this->applyType($this->alice, 'EL', '2026-12-21', '2026-12-24')->json('data.id');
        $this->act($this->bob, $approved, 'approve')->assertOk();
        $rejected = $this->applyType($this->alice, 'CL', '2026-12-01')->json('data.id');
        $this->act($this->bob, $rejected, 'reject', 'No cover')->assertOk();
        $cancelled = $this->applyType($this->alice, 'CL', '2026-12-02')->json('data.id');
        $this->act($this->alice, $cancelled, 'cancel')->assertOk();
        $actions = LeaveRequestAction::query()->count();

        $cases = [
            [$approved, 'approve', $this->bob], [$approved, 'reject', $this->bob], [$approved, 'approve', $this->carol],
            [$rejected, 'approve', $this->bob], [$rejected, 'reject', $this->carol], [$rejected, 'cancel', $this->alice], [$rejected, 'cancel', $this->carol],
            [$cancelled, 'approve', $this->bob], [$cancelled, 'reject', $this->bob], [$cancelled, 'cancel', $this->alice], [$cancelled, 'cancel', $this->carol],
        ];
        foreach ($cases as [$id, $action, $actor]) {
            $this->problem($this->act($actor, $id, $action, 'Some comment'), 409, 'INVALID_TRANSITION', "/leave-requests/{leaveRequestId}/{$action}");
        }

        $this->assertSame($actions, LeaveRequestAction::query()->count());
        $this->assertSame(-4.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Debit));
        $this->assertSame(0, LeaveLedgerEntry::query()->where('kind', LedgerKind::Reversal)->count());
    }

    public function test_owner_cancel_is_refused_from_the_start_date_on(): void
    {
        $startsToday = $this->applyType($this->alice, 'EL', '2026-12-21')->json('data.id');
        $startsTomorrow = $this->applyType($this->alice, 'EL', '2026-12-22')->json('data.id');
        $this->act($this->bob, $startsToday, 'approve')->assertOk();
        $this->act($this->bob, $startsTomorrow, 'approve')->assertOk();
        $this->travelTo('2026-12-21 08:00:00');

        $this->problem($this->act($this->alice, $startsToday, 'cancel'), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/cancel');
        $this->act($this->alice, $startsTomorrow, 'cancel')->assertOk();
        $this->assertSame(1.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Reversal));
    }

    public function test_hr_cancel_of_started_leave_reverses_exactly_once(): void
    {
        $id = $this->applyType($this->alice, 'EL', '2026-12-21', '2026-12-24')->json('data.id');
        $this->act($this->bob, $id, 'approve')->assertOk();
        $this->travelTo('2026-12-23 10:00:00');

        $this->act($this->carol, $id, 'cancel', 'Called back')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->problem($this->act($this->carol, $id, 'cancel'), 409, 'INVALID_TRANSITION', '/leave-requests/{leaveRequestId}/cancel');
        $this->assertSame(1, LeaveLedgerEntry::query()->where('kind', LedgerKind::Reversal)->count());
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
    }

    public function test_hr_cannot_cancel_or_decide_own_started_leave_another_hr_can(): void
    {
        $dan = User::factory()->hrAdmin()->create();
        $id = $this->applyType($this->carol, 'EL', '2026-12-21', '2026-12-22')->assertCreated()->json('data.id');
        $this->problem($this->act($this->carol, $id, 'reject', 'Self reject'), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/reject');
        $this->act($dan, $id, 'approve')->assertOk();
        $this->travelTo('2026-12-21 10:00:00');

        $this->problem($this->act($this->carol, $id, 'cancel'), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/cancel');
        $this->act($dan, $id, 'cancel')->assertOk();
    }

    /**
     * Policy `cancel` reads the unlocked route-bound row; the Action re-checks only the state machine under the lock.
     * A pending (backdated) request approved between the two lets the owner cancel leave that has already started.
     */
    public function test_owner_cancel_racing_an_approval_cannot_cancel_started_leave(): void
    {
        $id = $this->applyType($this->alice, 'SL', '2026-11-12')->assertCreated()->json('data.id');
        $raced = false;
        Gate::after(function (User $user, string $ability, $result, array $args) use (&$raced): void {
            if ($ability === 'cancel' && ! $raced) {
                $raced = true;
                app(ApproveLeaveRequest::class)->handle($args[0], $this->bob); // the manager's approval commits first
            }
        });

        $response = $this->act($this->alice, $id, 'cancel');

        $this->assertTrue($raced);
        $this->assertContains($response->status(), [403, 409], 'Owner cancelled leave that had already started.');
        $this->assertSame(LeaveStatus::Approved, LeaveRequest::query()->where('public_id', $id)->sole()->status);
    }

    // RBAC (USE_CASES §3.6, API_GUIDELINES §5)

    public function test_manager_sees_and_decides_direct_reports_only(): void
    {
        $dave = User::factory()->manager()->managedBy($this->bob)->create();
        $erin = User::factory()->managedBy($dave)->create();
        $this->grant($erin, 'EL', 30);
        $id = $this->applyType($erin, 'EL', '2026-12-01')->assertCreated()->json('data.id');

        $this->assertNotContains($id, array_column($this->api($this->bob)->getJson('/api/v1/leave-requests')->json('data'), 'id'));
        $this->assertNotContains($id, array_column($this->api($this->bob)->getJson('/api/v1/approvals')->json('data'), 'id'));
        $this->problem($this->api($this->bob)->getJson("/api/v1/leave-requests/{$id}"), 404, 'NOT_FOUND', '/leave-requests/{leaveRequestId}', 'get');
        $this->assertContains($this->act($this->bob, $id, 'approve')->status(), [403, 404]); // 404 expected, see known-bug test

        $this->assertContains($id, array_column($this->api($dave)->getJson('/api/v1/approvals')->json('data'), 'id'));
        $this->act($dave, $id, 'approve')->assertOk();
    }

    public function test_hr_admin_is_the_fallback_approver_without_an_active_manager(): void
    {
        $orphan = User::factory()->create();
        $this->grant($orphan, 'EL', 30);
        $id = $this->applyType($orphan, 'EL', '2026-12-01')->assertCreated()->json('data.id');
        $this->assertContains($id, array_column($this->api($this->carol)->getJson('/api/v1/approvals')->json('data'), 'id'));
        $this->act($this->carol, $id, 'approve')->assertOk();

        $gone = User::factory()->manager()->inactive()->create();
        $report = User::factory()->managedBy($gone)->create();
        $this->grant($report, 'EL', 30);
        $other = $this->applyType($report, 'EL', '2026-12-01')->assertCreated()->json('data.id');
        $this->act($gone, $other, 'approve')->assertUnauthorized();
        $this->act($this->carol, $other, 'approve')->assertOk();
    }

    public function test_sole_active_hr_admin_with_inactive_peer_has_no_approver(): void
    {
        User::factory()->hrAdmin()->inactive()->create();
        $this->problem($this->applyType($this->carol, 'EL', '2026-12-01'), 422, 'NO_APPROVER', '/leave-requests');
    }

    /** API_GUIDELINES §5: invisible resource → 404 "so ids cannot be probed"; 403 only for visible resources. */
    public function test_actions_on_an_invisible_request_are_404_not_403(): void
    {
        $id = $this->applyType($this->alice, 'EL', '2026-12-01')->json('data.id');
        $stranger = User::factory()->create();
        $otherManager = User::factory()->manager()->create();

        foreach ([[$stranger, 'cancel'], [$otherManager, 'approve'], [$otherManager, 'reject'], [$otherManager, 'cancel']] as [$actor, $action]) {
            $this->problem($this->act($actor, $id, $action, 'Probe'), 404, 'NOT_FOUND', "/leave-requests/{leaveRequestId}/{$action}");
        }
    }

    /** openapi x-roles for approve/reject = [manager, hr_admin]; an `employee` set as someone's manager_id must not decide. */
    public function test_employee_role_user_set_as_manager_cannot_approve(): void
    {
        $lead = User::factory()->create(); // base role employee
        $report = User::factory()->managedBy($lead)->create();
        $this->grant($report, 'EL', 30);
        $id = $this->applyType($report, 'EL', '2026-12-01')->assertCreated()->json('data.id');

        $this->problem($this->api($lead)->getJson('/api/v1/approvals'), 403, 'FORBIDDEN', '/approvals', 'get');
        $this->problem($this->act($lead, $id, 'approve'), 403, 'FORBIDDEN', '/leave-requests/{leaveRequestId}/approve');
    }
}
