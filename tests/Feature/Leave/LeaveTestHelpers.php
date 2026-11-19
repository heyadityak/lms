<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Enums\LedgerKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeKeycloak;

/** Shared fixture: today = Mon 2026-11-16, alice reports to bob, carol is HR, seeded CL/SL/EL/LWP with full grants. */
trait LeaveTestHelpers
{
    use FakeKeycloak;

    protected User $alice;

    protected User $bob;

    protected User $carol;

    protected function setUpLeave(): void
    {
        $this->travelTo('2026-11-16 09:00:00');
        $this->fakeKeycloak();
        $this->seed(LeaveTypeSeeder::class);
        $this->bob = User::factory()->manager()->create(['name' => 'Bob Manager']);
        $this->alice = User::factory()->managedBy($this->bob)->create(['name' => 'Alice Employee']);
        $this->carol = User::factory()->hrAdmin()->create(['name' => 'Carol HR']);
        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            foreach (['CL', 'SL', 'EL'] as $code) {
                $this->grant($user, $code, $this->type($code)->annual_quota);
            }
        }
    }

    /** Settings/Modules are scoped (per request in production); the test app is shared, so drop their memo. */
    protected function setting(string $key, mixed $value): void
    {
        app(Settings::class)->set($key, $value);
        $this->app->forgetScopedInstances();
    }

    protected function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->firstOrFail();
    }

    protected function grant(User $user, string $code, float $days, int $year = 2026): void
    {
        LeaveLedgerEntry::query()->create(['user_id' => $user->id, 'leave_type_id' => $this->type($code)->id, 'year' => $year, 'kind' => LedgerKind::Grant, 'days' => $days]);
    }

    /** Bearer JWT whose claims match the local user, so provisioning keeps role/name/email. */
    protected function api(User $user): static
    {
        return $this->withToken($this->jwt(
            ['sub' => $user->keycloak_sub, 'email' => $user->email, 'name' => $user->name],
            [$user->role->value],
        ));
    }

    /** @param  array<string, mixed>  $overrides */
    protected function apply(User $user, array $overrides = []): TestResponse
    {
        return $this->api($user)->postJson('/api/v1/leave-requests', array_merge([
            'leave_type_id' => $this->type('EL')->public_id,
            'start_date' => '2026-12-21',
            'end_date' => '2026-12-25',
            'day_part' => 'full',
            'reason' => 'Year-end family trip',
        ], $overrides));
    }

    protected function ledgerSum(User $user, string $code, ?LedgerKind $kind = null): float
    {
        return (float) LeaveLedgerEntry::query()->where(['user_id' => $user->id, 'leave_type_id' => $this->type($code)->id])
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))->sum('days');
    }
}
