<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAdminAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAdminAudit>
 */
class UserAdminAuditFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->admin(),
            'target_user_id' => User::factory(),
            'action' => 'enable',
            'changes' => ['is_active' => ['from' => false, 'to' => true]],
            'outcome' => 'success',
        ];
    }

    public function failed(string $errorCode = 'IDP_UNAVAILABLE'): static
    {
        return $this->state(['outcome' => 'failed', 'error_code' => $errorCode]);
    }
}
