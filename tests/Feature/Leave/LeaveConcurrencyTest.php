<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Actions\Leave\ApproveLeaveRequest;
use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Phase 7 QA: real races. Two OS processes (tests/Support/race-worker.php), each with its own Postgres connection,
 * fire Actions at the same request at the same instant; data is committed (DatabaseTruncation, not a transaction).
 */
final class LeaveConcurrencyTest extends TestCase
{
    use DatabaseTruncation, LeaveTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLeave();
    }

    protected function tearDown(): void
    {
        // Leave nothing committed behind for the RefreshDatabase suites.
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    /**
     * @param  list<array{0: string, 1: User}>  $jobs
     * @return list<string>
     */
    private function race(LeaveRequest $request, array $jobs): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 1.5); // covers both children booting the framework
        $procs = [];
        foreach ($jobs as [$action, $actor]) {
            $procs[] = proc_open([PHP_BINARY, base_path('tests/Support/race-worker.php'), $action, (string) $request->id, (string) $actor->id, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $outputs[] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $results[] = trim(stream_get_contents($outputs[$i][1]).stream_get_contents($outputs[$i][2]));
            proc_close($proc);
        }

        return $results;
    }

    private function pending(): LeaveRequest
    {
        return LeaveRequest::factory()->for($this->alice)->create([
            'leave_type_id' => $this->type('EL')->id, 'start_date' => '2026-12-21', 'end_date' => '2026-12-24', 'days' => 4, 'status' => LeaveStatus::Pending,
        ]);
    }

    private function kinds(LeaveRequest $request): array
    {
        return LeaveLedgerEntry::query()->where('leave_request_id', $request->id)->orderBy('id')->pluck('kind')->map->value->all();
    }

    public function test_two_simultaneous_approvals_debit_once(): void
    {
        $request = $this->pending();

        $results = $this->race($request, [['approve', $this->bob], ['approve', $this->carol]]);

        sort($results);
        $this->assertSame(['409', 'ok'], $results);
        $this->assertSame(['debit'], $this->kinds($request));
        $this->assertSame(LeaveStatus::Approved, $request->fresh()->status);
        $this->assertSame(1, $request->actions()->where('action', 'approved')->count());
    }

    public function test_approve_and_cancel_race_leaves_a_consistent_ledger(): void
    {
        $request = $this->pending();

        $results = $this->race($request, [['approve', $this->bob], ['cancel', $this->alice]]);

        $this->assertNotContains('', $results);
        $this->assertSame(LeaveStatus::Cancelled, $request->fresh()->status);
        $this->assertContains($this->kinds($request), [[], ['debit', 'reversal']], implode(' | ', $results));
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
    }

    public function test_approve_and_reject_race_has_one_winner(): void
    {
        $request = $this->pending();

        $results = $this->race($request, [['approve', $this->bob], ['reject', $this->carol]]);

        sort($results);
        $this->assertSame(['409', 'ok'], $results);
        $status = $request->fresh()->status;
        $this->assertSame($status === LeaveStatus::Approved ? ['debit'] : [], $this->kinds($request));
    }

    public function test_double_hr_cancel_of_approved_leave_reverses_once(): void
    {
        $request = $this->pending();
        app(ApproveLeaveRequest::class)->handle($request, $this->bob);
        $dan = User::factory()->hrAdmin()->create();

        $results = $this->race($request, [['cancel', $this->carol], ['cancel', $dan]]);

        sort($results);
        $this->assertSame(['409', 'ok'], $results);
        $this->assertSame([LedgerKind::Debit->value, LedgerKind::Reversal->value], $this->kinds($request));
        $this->assertSame(30.0, $this->ledgerSum($this->alice, 'EL'));
    }
}
