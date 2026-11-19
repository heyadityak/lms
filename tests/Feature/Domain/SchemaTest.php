<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserAdminAudit;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_and_demo_seeders(): void
    {
        $this->seed();
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class); // idempotent

        $this->assertSame(['CL', 'EL', 'LWP', 'SL'], LeaveType::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(7, LeaveType::query()->firstWhere('code', 'SL')->max_backdate_days);
        $this->assertFalse(LeaveType::query()->firstWhere('code', 'LWP')->tracks_balance);

        $alice = User::query()->firstWhere('email', 'alice@example.test');
        $this->assertNull($alice->keycloak_sub); // linked by verified email on first login
        $this->assertSame('bob@example.test', $alice->manager->email);
        $this->assertSame(Role::HrAdmin, User::query()->firstWhere('email', 'carol@example.test')->role);
        $this->assertTrue(User::query()->firstWhere('email', 'dave@example.test')->is_admin);
        $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $alice->public_id);
        $this->assertSame(12, LeaveLedgerEntry::query()->count()); // 4 users × CL/SL/EL grants
        $this->assertSame(10.0, (float) $alice->ledgerEntries()->whereHas('leaveType', fn ($q) => $q->where('code', 'CL'))->sum('days'));
    }

    public function test_overlap_exclusion_blocks_open_requests_only(): void
    {
        $user = User::factory()->create();
        $type = LeaveType::factory()->create();
        $make = fn (string $start, string $end) => LeaveRequest::factory()->for($user)->for($type)->between($start, $end, 1);

        $make('2026-12-07', '2026-12-09')->create();
        $this->assertOverlapRejected(fn () => $make('2026-12-09', '2026-12-10')->create());
        $this->assertOverlapRejected(fn () => $make('2026-12-08', '2026-12-08')->approved()->create());

        // Rejected/cancelled rows never block, and an existing closed row does not block a new open one.
        $make('2026-12-08', '2026-12-08')->rejected()->create();
        $make('2026-12-07', '2026-12-11')->cancelled()->create();
        $make('2026-12-14', '2026-12-14')->cancelled()->create();
        $make('2026-12-14', '2026-12-14')->create();
        // Another user's dates are independent.
        LeaveRequest::factory()->for($type)->between('2026-12-07', '2026-12-09', 3)->create();

        $this->assertSame(2, LeaveRequest::query()->where('user_id', $user->id)->whereIn('status', [LeaveStatus::Pending, LeaveStatus::Approved])->count());
    }

    public function test_check_constraints(): void
    {
        $this->assertViolates('23514', fn () => LeaveRequest::factory()->between('2026-12-09', '2026-12-08', 1)->create());
        $this->assertViolates('23514', fn () => LeaveRequest::factory()->between('2026-12-07', '2026-12-08', 0.5)->halfDay()->create(['end_date' => '2026-12-08']));
        $this->assertViolates('23514', fn () => LeaveRequest::factory()->create(['days' => 0.3]));
        $this->assertViolates('23514', fn () => User::factory()->create()->forceFill(['manager_id' => DB::raw('id')])->save());
    }

    public function test_ledger_is_append_only(): void
    {
        $entry = LeaveLedgerEntry::factory()->grant(10)->create();

        foreach ([fn () => $entry->update(['days' => 99]), fn () => $entry->delete(), fn () => tap($entry, fn ($e) => $e->days = 5)->save()] as $write) {
            try {
                $write();
                $this->fail('Ledger write was not blocked.');
            } catch (LogicException) {
            }
        }
        $this->assertSame(10.0, $entry->fresh()->days);

        // One yearly grant per (user, type, year).
        $this->assertViolates('23505', fn () => LeaveLedgerEntry::factory()->grant(1)->create($entry->only(['user_id', 'leave_type_id', 'year'])));

        $audit = UserAdminAudit::factory()->create();
        $this->expectException(LogicException::class);
        $audit->delete();
    }

    private function assertOverlapRejected(callable $insert): void
    {
        $this->assertViolates('23P01', $insert);
    }

    /** Runs $insert in a savepoint so the failed statement does not abort the test transaction. */
    private function assertViolates(string $sqlState, callable $insert): void
    {
        try {
            DB::transaction($insert);
            $this->fail("Expected SQLSTATE {$sqlState}.");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->getCode());
        }
    }
}
