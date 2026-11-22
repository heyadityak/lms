<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\LeaveYear;

/** Ledger-derived balances per leave year (USE_CASES §3.5): available = SUM(ledger) − pending requests. */
final class LeaveBalanceService
{
    public function balance(User $user, LeaveType $type, int $year): float
    {
        return (float) LeaveLedgerEntry::query()
            ->where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year])
            ->sum('days');
    }

    public function pending(User $user, LeaveType $type, int $year, ?int $exceptRequestId = null): float
    {
        return (float) LeaveRequest::query()
            ->where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'status' => LeaveStatus::Pending])
            ->whereBetween('start_date', [LeaveYear::start($year)->toDateString(), LeaveYear::end($year)->toDateString()])
            ->when($exceptRequestId !== null, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->sum('days');
    }

    public function available(User $user, LeaveType $type, int $year, ?int $exceptRequestId = null): float
    {
        return $this->balance($user, $type, $year) - $this->pending($user, $type, $year, $exceptRequestId);
    }

    /**
     * One row per active tracked type (openapi Balance): entitled, used, pending, balance, available.
     *
     * @return list<array{leave_type: LeaveType, year: int, entitled: float, used: float, pending: float, balance: float, available: float}>
     */
    public function summary(User $user, int $year): array
    {
        $ledger = LeaveLedgerEntry::query()
            ->where(['user_id' => $user->id, 'year' => $year])
            ->selectRaw('leave_type_id, kind, SUM(days) AS total')
            ->groupBy('leave_type_id', 'kind')
            ->get()
            ->groupBy('leave_type_id');

        $pending = LeaveRequest::query()
            ->where(['user_id' => $user->id, 'status' => LeaveStatus::Pending])
            ->whereBetween('start_date', [LeaveYear::start($year)->toDateString(), LeaveYear::end($year)->toDateString()])
            ->selectRaw('leave_type_id, SUM(days) AS total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        $usedKinds = [LedgerKind::Debit, LedgerKind::Reversal, LedgerKind::Encashment];

        return LeaveType::query()->where(['tracks_balance' => true, 'is_active' => true])->orderBy('code')->get()
            ->map(function (LeaveType $type) use ($ledger, $pending, $usedKinds, $year): array {
                $rows = $ledger->get($type->id, collect());
                $used = -(float) $rows->filter(fn ($r): bool => in_array($r->kind, $usedKinds, true))->sum('total');
                $balance = (float) $rows->sum('total');
                $pendingDays = (float) ($pending[$type->id] ?? 0);

                return [
                    'leave_type' => $type,
                    'year' => $year,
                    'entitled' => $balance + $used,
                    'used' => $used,
                    'pending' => $pendingDays,
                    'balance' => $balance,
                    'available' => $balance - $pendingDays,
                ];
            })->all();
    }
}
