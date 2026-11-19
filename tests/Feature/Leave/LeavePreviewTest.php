<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ValidatesOpenApi;
use Tests\TestCase;

/** Phase 11 (D-43): POST /leave-requests/preview, the dry run of UC-E5. Fixture as LeaveApiTest (today Mon 2026-11-16, Christmas holiday). */
final class LeavePreviewTest extends TestCase
{
    use LeaveTestHelpers, RefreshDatabase, ValidatesOpenApi;

    private const PATH = '/leave-requests/preview';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
        Holiday::query()->create(['date' => '2026-12-25', 'name' => 'Christmas Day']);
    }

    /** @param  array<string, mixed>  $headers */
    private function preview(User $user, string $code, string $start, ?string $end = null, string $part = 'full', array $headers = []): TestResponse
    {
        return $this->api($user)->postJson('/api/v1/leave-requests/preview', [
            'leave_type_id' => $this->type($code)->public_id,
            'start_date' => $start,
            'end_date' => $end ?? $start,
            'day_part' => $part,
            'reason' => 'Year-end family trip',
        ], $headers);
    }

    private function ok(TestResponse $response): TestResponse
    {
        $response->assertOk();
        $this->assertMatchesSpec($response, self::PATH, 'post');

        return $response;
    }

    private function problem(TestResponse $response, string $code): void
    {
        $response->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertMatchesSpec($response, self::PATH, 'post');
    }

    public function test_happy_path_returns_days_year_and_balances(): void
    {
        $this->ok($this->preview($this->alice, 'EL', '2026-12-21', '2026-12-25'))->assertExactJson(['data' => [
            'days' => 4, 'leave_year' => 2026, 'tracks_balance' => true, 'available_before' => 30, 'available_after' => 26,
        ], 'meta' => [], 'links' => []]);

        $this->ok($this->preview($this->alice, 'LWP', '2026-12-01'))
            ->assertJsonPath('data.tracks_balance', false)
            ->assertJsonPath('data.available_before', null)
            ->assertJsonPath('data.available_after', null);
    }

    public function test_pending_requests_reduce_available_before(): void
    {
        $this->apply($this->alice)->assertCreated(); // EL 21–25 Dec = 4 days pending
        $this->ok($this->preview($this->alice, 'EL', '2026-12-01', '2026-12-02'))
            ->assertJsonPath('data.available_before', 26)
            ->assertJsonPath('data.available_after', 24);
    }

    public function test_every_rule_failure_returns_the_submit_problem_code(): void
    {
        $this->problem($this->preview($this->alice, 'CL', '2026-11-13'), 'BACKDATE_LIMIT_EXCEEDED');
        $this->problem($this->preview($this->alice, 'EL', '2026-12-02', '2026-12-01'), 'INVALID_DATE_RANGE');
        $this->problem($this->preview($this->alice, 'LWP', '2026-12-31', '2027-01-01'), 'INVALID_DATE_RANGE');
        $this->problem($this->preview($this->alice, 'EL', '2026-12-01', part: 'first_half'), 'HALF_DAY_NOT_ALLOWED');
        $this->problem($this->preview($this->alice, 'CL', '2026-12-01', '2026-12-02', 'first_half'), 'HALF_DAY_NOT_ALLOWED');
        $this->problem($this->preview($this->alice, 'CL', '2026-11-21', '2026-11-22'), 'NO_WORKING_DAYS');
        $this->problem($this->preview($this->alice, 'CL', '2026-11-30', '2026-12-14'), 'INSUFFICIENT_BALANCE'); // 11 working days > 10 CL
        $this->problem($this->preview($this->carol, 'EL', '2026-12-01'), 'NO_APPROVER');

        $this->apply($this->alice)->assertCreated();
        $this->problem($this->preview($this->alice, 'CL', '2026-12-22'), 'LEAVE_OVERLAP');

        $this->problem($this->api($this->alice)->postJson('/api/v1/leave-requests/preview', ['leave_type_id' => $this->type('CL')->public_id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'day_part' => 'full', 'reason' => 'x']), 'VALIDATION_FAILED');

        $this->type('CL')->update(['is_active' => false]);
        $this->problem($this->preview($this->alice, 'CL', '2026-12-01'), 'LEAVE_TYPE_INACTIVE');
    }

    public function test_preview_writes_nothing_and_ignores_idempotency_key(): void
    {
        $counts = fn (): array => [LeaveRequest::query()->count(), LeaveRequestAction::query()->count(), LeaveLedgerEntry::query()->count()];
        $before = $counts();
        $key = (string) Str::uuid();

        $this->ok($this->preview($this->alice, 'EL', '2026-12-21', '2026-12-25', headers: ['Idempotency-Key' => $key]))->assertHeaderMissing('Idempotent-Replayed');
        // Same key, different body: a replay or 409 would mean the idempotency middleware ran.
        $this->ok($this->preview($this->alice, 'CL', '2026-12-01', headers: ['Idempotency-Key' => $key]))->assertJsonPath('data.days', 1);
        $this->problem($this->preview($this->alice, 'CL', '2026-11-21', '2026-11-22'), 'NO_WORKING_DAYS');

        $this->assertSame($before, $counts());
    }

    public function test_weekend_holiday_and_half_day_counts(): void
    {
        $this->ok($this->preview($this->alice, 'EL', '2026-12-19', '2026-12-27'))->assertJsonPath('data.days', 4); // Sat..Sun, minus weekends + Christmas
        $this->ok($this->preview($this->alice, 'CL', '2026-12-01', part: 'second_half'))->assertJsonPath('data.days', 0.5)->assertJsonPath('data.available_after', 9.5);

        $this->setting('modules.disabled', ['holidays']);
        $this->ok($this->preview($this->alice, 'EL', '2026-12-19', '2026-12-27'))->assertJsonPath('data.days', 5);

        $this->setting('work.weekend_days', [5, 6, 7]); // Fri–Sun weekend
        $this->ok($this->preview($this->alice, 'EL', '2026-12-21', '2026-12-27'))->assertJsonPath('data.days', 4);
    }

    public function test_april_leave_year(): void
    {
        config(['lms.leave_year_start_month' => 4]);
        $this->grant($this->alice, 'CL', 3, 2027);

        $this->ok($this->preview($this->alice, 'CL', '2027-03-31'))->assertJsonPath('data.leave_year', 2026)->assertJsonPath('data.available_before', 10);
        $this->ok($this->preview($this->alice, 'CL', '2027-04-01'))->assertJsonPath('data.leave_year', 2027)->assertJsonPath('data.available_before', 3);
        $this->problem($this->preview($this->alice, 'CL', '2027-03-31', '2027-04-01'), 'INVALID_DATE_RANGE');
    }

    public function test_preview_and_submit_agree(): void
    {
        $cases = [
            ['EL', '2026-11-16', '2026-11-20', 'full'],
            ['EL', '2026-11-21', '2026-11-30', 'full'],
            ['SL', '2026-11-10', '2026-11-13', 'full'], // backdated within 7 days
            ['CL', '2026-12-01', '2026-12-01', 'first_half'],
            ['CL', '2026-12-02', '2026-12-02', 'second_half'],
            ['EL', '2026-12-21', '2027-01-08', 'full'], // crosses 31 Dec
            ['LWP', '2026-12-24', '2026-12-28', 'full'],
            ['EL', '2026-11-20', '2026-11-20', 'full'], // overlaps the first
            ['CL', '2026-12-03', '2026-12-16', 'full'], // 10 days > 9 CL left
            ['CL', '2026-12-03', '2026-12-15', 'full'],
        ];
        foreach ($cases as [$code, $start, $end, $part]) {
            $preview = $this->preview($this->alice, $code, $start, $end, $part);
            $submit = $this->apply($this->alice, ['leave_type_id' => $this->type($code)->public_id, 'start_date' => $start, 'end_date' => $end, 'day_part' => $part]);

            $label = "{$code} {$start}..{$end} {$part}";
            $this->assertSame($preview->status() === 200 ? 201 : $preview->status(), $submit->status(), $label);
            if ($preview->status() === 200) {
                $this->assertEquals([$preview->json('data.days'), $preview->json('data.leave_year')], [$submit->json('data.days'), $submit->json('data.leave_year')], $label);
            } else {
                $this->assertSame($preview->json('code'), $submit->json('code'), $label);
            }
        }
    }
}
