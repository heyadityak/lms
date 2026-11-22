<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\Enums\AccrualFrequency;
use App\Enums\LedgerKind;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AccrualCalculator;
use App\Services\DepartmentQuota;
use App\Services\LeaveBalanceService;
use App\Support\LeaveYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * UC-S1 / UC-H9 (USE_CASES §3.7): per active user with a joining date and active tracked type, lapse above the cap
 * in year−1, carry_forward and grant in year. Idempotent: the yearly partial unique index + ON CONFLICT DO NOTHING
 * skip rows that exist, and after a lapse the old closing equals the carry, so a re-run computes the same rows.
 */
final class RunYearStartAccrual
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function handle(int $year): AccrualSummary
    {
        $types = LeaveType::query()->where(['tracks_balance' => true, 'is_active' => true])->orderBy('id')->get();
        $startMonth = (int) config('lms.leave_year_start_month', 1);
        $users = 0;
        $written = 0;
        $skipped = 0;

        $query = User::query()->where('is_active', true)->whereNotNull('date_of_joining')
            ->where('date_of_joining', '<=', LeaveYear::end($year)->toDateString());

        foreach ($query->lazyById() as $user) {
            $users++;
            DB::transaction(function () use ($user, $types, $year, $startMonth, &$written, &$skipped): void {
                foreach ($types as $type) {
                    $closing = $this->balances->balance($user, $type, $year - 1);
                    $carry = AccrualCalculator::carry($closing, $type->carry_forward_cap);
                    $quota = DepartmentQuota::for($user, $type);
                    $grant = 0.0;
                    if ($type->accrual_frequency !== AccrualFrequency::Monthly) {
                        $grant = LeaveYear::of($user->date_of_joining) === $year
                            ? AccrualCalculator::prorate($quota, $user->date_of_joining->month, $startMonth)
                            : $quota;
                        $grant = AccrualCalculator::clipGrant($grant, $carry, $type->max_balance);
                    }

                    $rows = [
                        [LedgerKind::Lapse, $year - 1, -max(0.0, $closing - $carry), 'Year-start lapse above carry-forward cap'],
                        [LedgerKind::CarryForward, $year, $carry, 'Carried forward from '.($year - 1)],
                        [LedgerKind::Grant, $year, $grant, 'Annual entitlement'],
                    ];
                    foreach ($rows as [$kind, $rowYear, $days, $note]) {
                        if ($days == 0.0) {
                            continue;
                        }
                        $inserted = DB::table('leave_ledger_entries')->insertOrIgnore([
                            'public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'leave_type_id' => $type->id,
                            'year' => $rowYear, 'kind' => $kind->value, 'days' => $days, 'note' => $note, 'created_at' => now(),
                        ]);
                        $inserted > 0 ? $written++ : $skipped++;
                    }
                }
            });
        }

        $summary = new AccrualSummary($year, $users, $written, $skipped);
        Log::info('accrual.run', (array) $summary);

        return $summary;
    }
}
