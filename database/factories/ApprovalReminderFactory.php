<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalReminder>
 */
class ApprovalReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->manager(),
            'reminded_on' => now()->toDateString(),
        ];
    }
}
