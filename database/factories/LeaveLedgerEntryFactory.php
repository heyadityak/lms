<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LedgerKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LeaveYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default: a yearly grant of 10 days in the current leave year.
 *
 * @extends Factory<LeaveLedgerEntry>
 */
class LeaveLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => LeaveYear::current(),
            'kind' => LedgerKind::Grant,
            'days' => 10,
        ];
    }

    public function grant(float $days): static
    {
        return $this->state(['kind' => LedgerKind::Grant, 'days' => $days]);
    }

    /** Debit for an approved request (negative days). */
    public function debitFor(LeaveRequest $request): static
    {
        return $this->state([
            'user_id' => $request->user_id,
            'leave_type_id' => $request->leave_type_id,
            'year' => $request->leaveYear(),
            'kind' => LedgerKind::Debit,
            'days' => -$request->days,
            'leave_request_id' => $request->id,
            'actor_id' => $request->decided_by,
        ]);
    }

    public function adjustment(float $days, User $actor, string $note = 'Manual correction'): static
    {
        return $this->state(['kind' => LedgerKind::Adjustment, 'days' => $days, 'actor_id' => $actor->id, 'note' => $note]);
    }
}
