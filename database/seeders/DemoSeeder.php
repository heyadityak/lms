<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LedgerKind;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LeaveYear;
use Illuminate\Database\Seeder;

/**
 * DEV ONLY (local/testing). HR data for the Keycloak dev realm users (docker/keycloak/realm-lms.json).
 * Rows are keyed by email with keycloak_sub = null; the first login links them by verified email (D-39 d).
 * Idempotent: safe to re-run.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('DemoSeeder runs only in local/testing; skipped.');

            return;
        }

        $this->call(LeaveTypeSeeder::class);

        $it = Department::query()->firstOrCreate(['code' => 'IT'], ['name' => 'Information Technology']);
        $hr = Department::query()->firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);

        $year = LeaveYear::current();
        $this->seedHolidays($year);

        $joined = LeaveYear::start($year)->subYears(2)->toDateString();
        $bob = $this->user('bob@example.test', 'Bob Manager', Role::Manager, false, $it, null, 'E002', 'Engineering Manager', $joined);
        $users = [
            $this->user('alice@example.test', 'Alice Employee', Role::Employee, false, $it, $bob, 'E001', 'Software Engineer', $joined),
            $bob,
            $this->user('carol@example.test', 'Carol HR', Role::HrAdmin, false, $hr, null, 'E003', 'HR Manager', $joined),
            $this->user('dave@example.test', 'Dave Admin', Role::Employee, true, $it, $bob, 'E004', 'IT Administrator', $joined),
        ];

        // Opening balances: one grant per tracked type for the current leave year (unique yearly row ⇒ idempotent).
        $types = LeaveType::query()->where('tracks_balance', true)->where('is_active', true)->get();
        foreach ($users as $user) {
            foreach ($types as $type) {
                LeaveLedgerEntry::query()->firstOrCreate(
                    ['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => LedgerKind::Grant],
                    ['days' => $type->annual_quota, 'note' => 'Opening balance (demo)'],
                );
            }
        }
    }

    private function user(string $email, string $name, Role $role, bool $isAdmin, Department $department, ?User $manager, string $code, string $designation, string $joined): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        // name/role/is_admin are re-synced from Keycloak on every login; seeded to match the dev realm.
        $user->forceFill([
            'name' => $name,
            'role' => $role,
            'is_admin' => $isAdmin,
            'department_id' => $department->id,
            'manager_id' => $manager?->id,
            'employee_code' => $code,
            'designation' => $designation,
            'employment_type' => 'full_time',
            'date_of_joining' => $joined,
        ])->save();

        return $user;
    }

    /** Fixed-date holidays mapped into leave year $year (works for any LEAVE_YEAR_START_MONTH). */
    private function seedHolidays(int $year): void
    {
        $start = LeaveYear::start($year);
        foreach (['01-01' => "New Year's Day", '05-01' => 'Labour Day', '08-15' => 'Independence Day', '10-02' => 'Gandhi Jayanti', '12-25' => 'Christmas Day'] as $md => $name) {
            $date = $start->setDate($year, (int) substr($md, 0, 2), (int) substr($md, 3, 2));
            if ($date->lt($start)) {
                $date = $date->addYear();
            }
            Holiday::query()->firstOrCreate(['date' => $date->toDateString()], ['name' => $name]);
        }
    }
}
