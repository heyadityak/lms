<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('?????')),
            'name' => fake()->words(2, true).' Leave',
            'annual_quota' => 12,
            'tracks_balance' => true,
            'carry_forward_cap' => null,
            'max_balance' => null,
            'allow_half_day' => true,
            'max_backdate_days' => 0,
            'is_active' => true,
        ];
    }

    /** Like LWP: no ledger, no balance check. */
    public function untracked(): static
    {
        return $this->state(['annual_quota' => 0, 'tracks_balance' => false]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function fullDayOnly(): static
    {
        return $this->state(['allow_half_day' => false]);
    }
}
