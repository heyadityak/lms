<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeaveAction;
use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequestAction>
 */
class LeaveRequestActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'leave_request_id' => LeaveRequest::factory(),
            'actor_id' => User::factory(),
            'action' => LeaveAction::Submitted,
            'from_status' => null,
            'to_status' => LeaveStatus::Pending,
        ];
    }
}
