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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monthly types only: grant annual_quota/12 (or the department override) once per user, type and month.
 * Yearly types are skipped so a calendar month never adds a second annual grant.
 */
final class RunMonthlyAccrual
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function handle(): int
    {
        $on = CarbonImmutable::now()->startOfDay();
        $leaveYear = LeaveYear::of($on);
        $month = $on->month;
        $types = LeaveType::query()->where([
            'tracks_balance' => true,
            'is_active' => true,
            'accrual_frequency' => AccrualFrequency::Monthly,
        ])->orderBy('id')->get();
        if ($types->isEmpty()) {
            return 0;
        }

        $written = 0;
        $users = User::query()->where('is_active', true)->whereNotNull('date_of_joining')
            ->where('date_of_joining', '<=', $on->toDateString());

        foreach ($users->lazyById() as $user) {
            DB::transaction(function () use ($user, $types, $leaveYear, $month, &$written): void {
                foreach ($types as $type) {
                    $amount = AccrualCalculator::monthly(DepartmentQuota::for($user, $type));
                    $amount = AccrualCalculator::clipGrant($amount, $this->balances->balance($user, $type, $leaveYear), $type->max_balance);
                    if ($amount <= 0.0) {
                        continue;
                    }
                    $written += DB::table('leave_ledger_entries')->insertOrIgnore([
                        'public_id' => (string) Str::ulid(),
                        'user_id' => $user->id,
                        'leave_type_id' => $type->id,
                        'year' => $leaveYear,
                        'accrual_month' => $month,
                        'kind' => LedgerKind::Grant->value,
                        'days' => $amount,
                        'note' => 'Monthly entitlement',
                        'created_at' => now(),
                    ]);
                }
            });
        }

        return $written;
    }
}
