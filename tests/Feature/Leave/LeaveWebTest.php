<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 6 core flow through the Blade UI (session auth via actingAs). */
final class LeaveWebTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['leave_type_id' => $this->type('EL')->public_id, 'start_date' => '2026-12-21', 'end_date' => '2026-12-24', 'day_part' => 'full', 'reason' => 'Family trip'], $overrides);
    }

    public function test_dashboard_shows_balances_and_guest_sees_landing(): void
    {
        $this->get('/')->assertOk()->assertSee('Log in');
        $this->actingAs($this->alice)->get('/')->assertOk()
            ->assertSee('Balances for leave year 2026')->assertSee('Earned Leave (EL)')->assertSee('Apply');
    }

    public function test_apply_approve_cancel_flow(): void
    {
        $this->actingAs($this->alice)->get('/leave-requests/create')->assertOk()->assertSee('<label for="start_date">', false);
        $this->actingAs($this->alice)->post('/leave-requests', $this->form())->assertRedirect();
        $request = LeaveRequest::query()->sole();
        $this->assertSame(4.0, $request->days);

        $this->actingAs($this->alice)->get('/leave-requests')->assertOk()->assertSee('2026-12-21 to 2026-12-24')->assertSee('Cancel');
        $this->actingAs($this->alice)->get("/leave-requests/{$request->public_id}")->assertOk()->assertSee('Submitted by Alice Employee');

        $this->actingAs($this->bob)->get('/approvals')->assertOk()->assertSee('Alice Employee')->assertSee('Approve');
        $this->actingAs($this->bob)->post("/leave-requests/{$request->public_id}/approve", ['comment' => 'ok'])->assertRedirect('/approvals');
        $this->assertSame(LeaveStatus::Approved, $request->refresh()->status);
        $this->assertSame(-4.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Debit));

        $this->actingAs($this->alice)->post("/leave-requests/{$request->public_id}/cancel")->assertRedirect();
        $this->assertSame(LeaveStatus::Cancelled, $request->refresh()->status);
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
    }

    public function test_reject_needs_comment_and_rule_errors_go_back_to_form(): void
    {
        $this->actingAs($this->alice)->post('/leave-requests', $this->form())->assertRedirect();
        $request = LeaveRequest::query()->sole();

        $this->actingAs($this->bob)->from('/approvals')->post("/leave-requests/{$request->public_id}/reject", ['comment' => ''])
            ->assertRedirect('/approvals')->assertSessionHasErrors('comment');
        $this->actingAs($this->bob)->post("/leave-requests/{$request->public_id}/reject", ['comment' => 'Release week'])->assertRedirect('/approvals');
        $this->assertSame(LeaveStatus::Rejected, $request->refresh()->status);

        $this->actingAs($this->alice)->from('/leave-requests/create')->post('/leave-requests', $this->form(['start_date' => '2026-12-26', 'end_date' => '2026-12-27']))
            ->assertRedirect('/leave-requests/create')->assertSessionHasErrors(['start_date' => 'Selected dates contain no working days.']);
    }

    public function test_approvals_forbidden_for_employee_and_nav_hides_it(): void
    {
        $this->actingAs($this->alice)->get('/approvals')->assertForbidden();
        $this->actingAs($this->alice)->get('/')->assertDontSee('href="'.url('/approvals').'"', false);
        $this->actingAs($this->bob)->get('/')->assertSee('href="'.url('/approvals').'"', false);
    }

    public function test_holidays_page_is_module_gated(): void
    {
        Holiday::query()->create(['date' => '2026-12-25', 'name' => 'Christmas Day']);
        $this->actingAs($this->alice)->get('/holidays')->assertOk()->assertSee('Christmas Day');

        $this->setting('modules.disabled', ['holidays']);
        $this->actingAs($this->alice)->get('/holidays')->assertNotFound();
    }

    public function test_deactivated_user_is_logged_out(): void
    {
        $this->alice->forceFill(['is_active' => false])->save();
        $this->actingAs($this->alice)->get('/leave-requests')->assertForbidden();
        $this->assertGuest();
    }
}
