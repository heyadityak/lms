<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/** Default leave types (USE_CASES §3.1). Idempotent by code; re-runs reset the seeded values. */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'CL', 'name' => 'Casual Leave', 'annual_quota' => 10, 'tracks_balance' => true, 'carry_forward_cap' => 0, 'max_balance' => null, 'allow_half_day' => true, 'max_backdate_days' => 0],
            ['code' => 'SL', 'name' => 'Sick Leave', 'annual_quota' => 15, 'tracks_balance' => true, 'carry_forward_cap' => null, 'max_balance' => null, 'allow_half_day' => true, 'max_backdate_days' => config('lms.sl_max_backdate_days')],
            ['code' => 'EL', 'name' => 'Earned Leave', 'annual_quota' => 30, 'tracks_balance' => true, 'carry_forward_cap' => null, 'max_balance' => 300, 'allow_half_day' => false, 'max_backdate_days' => 0],
            ['code' => 'LWP', 'name' => 'Leave Without Pay', 'annual_quota' => 0, 'tracks_balance' => false, 'carry_forward_cap' => null, 'max_balance' => null, 'allow_half_day' => true, 'max_backdate_days' => 0],
        ];

        foreach ($types as $type) {
            // firstOrCreate: never overwrite HR edits (UC-H3) on a re-seed of a live deployment.
            LeaveType::query()->firstOrCreate(['code' => $type['code']], $type + ['is_active' => true]);
        }
    }
}
