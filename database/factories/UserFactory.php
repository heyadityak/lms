<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'keycloak_sub' => (string) Str::uuid(),
            'role' => Role::Employee,
            'is_admin' => false,
            'is_active' => true,
            'date_of_joining' => now()->subYears(2)->startOfMonth()->toDateString(),
        ];
    }

    public function manager(): static
    {
        return $this->state(['role' => Role::Manager]);
    }

    public function hrAdmin(): static
    {
        return $this->state(['role' => Role::HrAdmin]);
    }

    /** Additive realm role `admin` (D-29). */
    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Pre-created by HR/admin, not yet linked to a Keycloak account. */
    public function unlinked(): static
    {
        return $this->state(['keycloak_sub' => null]);
    }

    public function managedBy(User $manager): static
    {
        return $this->state(['manager_id' => $manager->id]);
    }

    public function inDepartment(?Department $department = null): static
    {
        return $this->state(['department_id' => $department ?? Department::factory()]);
    }
}
