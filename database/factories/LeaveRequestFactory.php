<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DayPart;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw rows for tests; real requests go through SubmitLeaveRequest (server-computed days).
 * Default: one future working day (next Monday), pending.
 *
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        $day = now()->next('Monday')->toDateString();

        return [
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => $day,
            'end_date' => $day,
            'day_part' => DayPart::Full,
            'days' => 1,
            'status' => LeaveStatus::Pending,
            'reason' => fake()->sentence(),
        ];
    }

    /** @param  string  $start  Y-m-d; $days is the stored working-day count, not recomputed here. */
    public function between(string $start, string $end, float $days): static
    {
        return $this->state(['start_date' => $start, 'end_date' => $end, 'days' => $days]);
    }

    public function halfDay(DayPart $part = DayPart::FirstHalf): static
    {
        return $this->state(fn (array $a): array => ['day_part' => $part, 'end_date' => $a['start_date'], 'days' => 0.5]);
    }

    public function approved(?User $by = null): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveStatus::Approved,
            'decided_by' => $by ?? User::factory()->manager(),
            'decided_at' => now(),
        ]);
    }

    public function rejected(?User $by = null): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveStatus::Rejected,
            'decided_by' => $by ?? User::factory()->manager(),
            'decided_at' => now(),
            'decision_comment' => 'Team is short-staffed.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => LeaveStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
