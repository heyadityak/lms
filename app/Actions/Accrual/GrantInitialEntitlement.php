<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\Enums\AccrualFrequency;
use App\Enums\LedgerKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AccrualCalculator;
use App\Services\DepartmentQuota;
use App\Support\LeaveYear;
use Illuminate\Support\Facades\DB;

/**
 * USE_CASES §3.7: entitlement for the current leave year once `date_of_joining` is first known
 * (first-login claim now; HR edit in phase 8). Idempotent through the yearly unique (user, type, year, grant).
 */
final class GrantInitialEntitlement
{
    public function handle(User $user): void
    {
        if ($user->date_of_joining === null) {
            return;
        }

        $year = LeaveYear::current();
        $joinYear = LeaveYear::of($user->date_of_joining);
        if ($joinYear > $year) {
            return; // joins in a later leave year: the year-start job grants it
        }

        DB::transaction(function () use ($user, $year, $joinYear): void {
            foreach (LeaveType::query()->where(['tracks_balance' => true, 'is_active' => true])->get() as $type) {
                $quota = DepartmentQuota::for($user, $type);
                if ($type->accrual_frequency === AccrualFrequency::Monthly) {
                    $amount = AccrualCalculator::monthly($quota);
                    if ($amount <= 0) {
                        continue;
                    }
                    LeaveLedgerEntry::query()->firstOrCreate(
                        ['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => LedgerKind::Grant, 'accrual_month' => (int) now()->month],
                        ['days' => $amount, 'note' => 'Monthly entitlement'],
                    );

                    continue;
                }

                $grant = $joinYear < $year
                    ? $quota
                    : AccrualCalculator::prorate($quota, $user->date_of_joining->month, (int) config('lms.leave_year_start_month', 1));
                $grant = AccrualCalculator::clipGrant($grant, 0.0, $type->max_balance);
                if ($grant <= 0) {
                    continue;
                }

                LeaveLedgerEntry::query()->firstOrCreate(
                    ['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => LedgerKind::Grant, 'accrual_month' => null],
                    ['days' => $grant, 'note' => $joinYear < $year ? 'Initial entitlement' : 'Prorated initial entitlement'],
                );
            }
        });
    }
}
