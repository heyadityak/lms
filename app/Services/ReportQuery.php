<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\LedgerKind;
use App\Models\LeaveRequest;
use App\Support\LeaveYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * UC-H7 / UC-H8 report rows in the openapi row shapes (LeaveRequestReportRow, BalanceReportRow), shared by the
 * paginated JSON and the streamed CSV. Department = name, leave type = code.
 */
final class ReportQuery
{
    public const LEAVE_REQUEST_COLUMNS = ['request_id', 'employee_code', 'employee', 'department', 'leave_type', 'start_date', 'end_date', 'day_part', 'days', 'status', 'decided_by', 'decided_at'];

    public const BALANCE_COLUMNS = ['employee_code', 'employee', 'department', 'leave_type', 'year', 'entitled', 'used', 'pending', 'balance', 'available'];

    /**
     * @param  array<string, string>  $filter  from, to, department, leave_type, status
     * @return Builder<LeaveRequest>
     */
    public static function leaveRequests(array $filter): Builder
    {
        return LeaveRequestQuery::filter(LeaveRequest::query(), $filter)
            ->with(['user.department', 'leaveType', 'decider'])
            ->orderBy('start_date')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public static function leaveRequestRow(LeaveRequest $r): array
    {
        return [
            'request_id' => $r->public_id,
            'employee_code' => $r->user->employee_code,
            'employee' => $r->user->name,
            'department' => $r->user->department?->name,
            'leave_type' => $r->leaveType->code,
            'start_date' => $r->start_date->toDateString(),
            'end_date' => $r->end_date->toDateString(),
            'day_part' => $r->day_part->value,
            'days' => $r->days,
            'status' => $r->status->value,
            'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * One row per user × active tracked leave type for leave year $year (same maths as LeaveBalanceService::summary).
     *
     * @param  array<string, string>  $filter  department, leave_type
     */
    public static function balances(int $year, array $filter): QueryBuilder
    {
        $start = LeaveYear::start($year)->toDateString();
        $end = LeaveYear::end($year)->toDateString();
        $ledger = 'SELECT COALESCE(SUM(l.days), 0) FROM leave_ledger_entries l WHERE l.user_id = u.id AND l.leave_type_id = t.id AND l.year = ?';

        return DB::table('users as u')
            ->crossJoin('leave_types as t')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->where(['t.tracks_balance' => true, 't.is_active' => true])
            ->when(isset($filter['department']), fn (QueryBuilder $q) => $q->where('d.public_id', $filter['department']))
            ->when(isset($filter['leave_type']), fn (QueryBuilder $q) => $q->where('t.public_id', $filter['leave_type']))
            ->select(['u.employee_code', 'u.name as employee', 'd.name as department', 't.code as leave_type'])
            ->selectRaw("({$ledger}) AS balance", [$year])
            ->selectRaw("-({$ledger} AND l.kind IN (?, ?)) AS used", [$year, LedgerKind::Debit->value, LedgerKind::Reversal->value])
            ->selectRaw('(SELECT COALESCE(SUM(r.days), 0) FROM leave_requests r WHERE r.user_id = u.id AND r.leave_type_id = t.id AND r.status = ? AND r.start_date BETWEEN ? AND ?) AS pending', [LeaveStatus::Pending->value, $start, $end])
            ->orderBy('u.name')->orderBy('u.id')->orderBy('t.code');
    }

    /** @return array<string, mixed> */
    public static function balanceRow(object $row, int $year): array
    {
        $balance = (float) $row->balance;
        $used = (float) $row->used + 0.0; // -0.0 → 0.0
        $pending = (float) $row->pending;

        return [
            'employee_code' => $row->employee_code,
            'employee' => $row->employee,
            'department' => $row->department,
            'leave_type' => $row->leave_type,
            'year' => $year,
            'entitled' => $balance + $used,
            'used' => $used,
            'pending' => $pending,
            'balance' => $balance,
            'available' => $balance - $pending,
        ];
    }
}
