<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Enums\LedgerKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\LeaveTestHelpers;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/**
 * Phase 13 edges named in AGENTS §6 that the HR happy path does not cover,
 * and that tests/Feature/Leave does not already assert.
 */
final class HrAdminEdgeTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    public function test_year_start_accrual_lapses_cl_carries_sl_and_clips_el(): void
    {
        LeaveType::query()->where('code', 'EL')->update(['max_balance' => 40]);

        $this->artisan('lms:accrue-year', ['year' => 2027])->assertSuccessful();

        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            $this->assertSame(-10.0, $this->days($user, 'CL', LedgerKind::Lapse, 2026));
            $this->assertSame(0.0, $this->days($user, 'CL', LedgerKind::CarryForward, 2027));
            $this->assertSame(10.0, $this->days($user, 'CL', LedgerKind::Grant, 2027));

            $this->assertSame(0.0, $this->days($user, 'SL', LedgerKind::Lapse, 2026));
            $this->assertSame(15.0, $this->days($user, 'SL', LedgerKind::CarryForward, 2027));
            $this->assertSame(15.0, $this->days($user, 'SL', LedgerKind::Grant, 2027));

            // Closing 30, cap 40 ⇒ carry 30 and the grant is clipped from 30 to 10.
            $this->assertSame(0.0, $this->days($user, 'EL', LedgerKind::Lapse, 2026));
            $this->assertSame(30.0, $this->days($user, 'EL', LedgerKind::CarryForward, 2027));
            $this->assertSame(10.0, $this->days($user, 'EL', LedgerKind::Grant, 2027));
        }

        $count = LeaveLedgerEntry::query()->count();
        $this->artisan('lms:accrue-year', ['year' => 2027])
            ->expectsOutputToContain('0 ledger rows written')
            ->assertSuccessful();
        $this->assertSame($count, LeaveLedgerEntry::query()->count());
    }

    public function test_notifications_module_off_sends_no_leave_mail(): void
    {
        Notification::fake();
        $this->setting('modules.disabled', ['notifications']);

        $this->apply($this->alice)->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_reports_module_off_is_not_found(): void
    {
        $this->setting('modules.disabled', ['reports']);

        $this->problem(
            $this->api($this->carol)->getJson('/api/v1/reports/leave-requests'),
            404,
            'NOT_FOUND',
            '/reports/leave-requests',
        );
        $this->problem(
            $this->api($this->carol)->getJson('/api/v1/reports/balances'),
            404,
            'NOT_FOUND',
            '/reports/balances',
        );
        $this->spec($this->api($this->carol)->getJson('/api/v1/leave-requests'), '/leave-requests')->assertOk();
    }

    public function test_reports_reject_an_unsupported_accept_header(): void
    {
        $leave = $this->api($this->carol)->get('/api/v1/reports/leave-requests', ['Accept' => 'application/xml']);
        $this->problem($leave, 406, 'NOT_ACCEPTABLE', '/reports/leave-requests');

        $balances = $this->api($this->carol)->get('/api/v1/reports/balances', ['Accept' => 'text/plain']);
        $this->problem($balances, 406, 'NOT_ACCEPTABLE', '/reports/balances');
    }

    public function test_hr_admin_cannot_deactivate_themselves(): void
    {
        $this->problem(
            $this->api($this->carol)->postJson("/api/v1/users/{$this->carol->public_id}/deactivate"),
            403,
            'FORBIDDEN',
            '/users/{userId}/deactivate',
            'post',
        );
        $this->assertTrue($this->carol->refresh()->is_active);
    }

    public function test_manager_cycle_is_rejected(): void
    {
        $this->problem(
            $this->api($this->carol)->patchJson("/api/v1/users/{$this->bob->public_id}", [
                'manager_id' => $this->alice->public_id,
            ]),
            422,
            'MANAGER_CYCLE',
            '/users/{userId}',
            'patch',
        );
        $this->assertNull($this->bob->refresh()->manager_id);

        $this->problem(
            $this->api($this->carol)->patchJson("/api/v1/users/{$this->alice->public_id}", [
                'manager_id' => $this->alice->public_id,
            ]),
            422,
            'MANAGER_CYCLE',
            '/users/{userId}',
            'patch',
        );
        $this->assertSame($this->bob->id, $this->alice->refresh()->manager_id);
    }

    public function test_adjustment_cannot_drive_the_balance_below_zero(): void
    {
        $this->problem(
            $this->api($this->carol)->postJson("/api/v1/users/{$this->alice->public_id}/ledger-entries", [
                'leave_type_id' => $this->type('EL')->public_id,
                'days' => -31,
                'note' => 'Too large a debit',
            ]),
            422,
            'INSUFFICIENT_BALANCE',
            '/users/{userId}/ledger-entries',
            'post',
        );

        $this->assertSame(0.0, $this->ledgerSum($this->alice, 'EL', LedgerKind::Adjustment));
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
    }

    private function days(User $user, string $code, LedgerKind $kind, int $year): float
    {
        return (float) LeaveLedgerEntry::query()->where([
            'user_id' => $user->id,
            'leave_type_id' => $this->type($code)->id,
            'kind' => $kind,
            'year' => $year,
        ])->sum('days');
    }

    private function spec(TestResponse $response, string $path, string $method = 'get'): TestResponse
    {
        $this->assertMatchesSpec($response, $path, $method);

        return $response;
    }

    private function problem(TestResponse $response, int $status, string $code, string $path, string $method = 'get'): void
    {
        $response->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, $path, $method);
    }
}
