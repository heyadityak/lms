<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ListLeaveRequestsRequest;
use App\Http\Resources\ReportRowResource;
use App\Services\ReportQuery;
use App\Support\LeaveYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UC-H7 / UC-H8 (module `reports`, hr_admin). `Accept: text/csv` streams the whole result (no pagination),
 * anything else that is not JSON → 406 (API_GUIDELINES §6).
 */
final class ReportController
{
    public function leaveRequests(ListLeaveRequestsRequest $request): JsonResponse|StreamedResponse
    {
        $query = ReportQuery::leaveRequests($request->filters());

        if (self::wantsCsv($request)) {
            return self::csv('leave-requests', ReportQuery::LEAVE_REQUEST_COLUMNS, (function () use ($query) {
                foreach ($query->lazy(500) as $row) {
                    yield ReportQuery::leaveRequestRow($row);
                }
            })());
        }

        $page = $query->paginate($request->pageSize(), ['*'], 'page[number]', $request->pageNumber())->through(ReportQuery::leaveRequestRow(...));

        return ReportRowResource::page($page, $request);
    }

    public function balances(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'filter' => ['sometimes', 'array:year,department,leave_type'],
            'filter.year' => ['sometimes', 'integer', 'between:2000,2100'],
            'filter.department' => ['sometimes', 'string', 'size:26'],
            'filter.leave_type' => ['sometimes', 'string', 'size:26'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'between:1,10000'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $filter = $validated['filter'] ?? [];
        $year = (int) ($filter['year'] ?? LeaveYear::current());
        $query = ReportQuery::balances($year, $filter);
        $row = fn (object $r): array => ReportQuery::balanceRow($r, $year);

        if (self::wantsCsv($request)) {
            return self::csv('balances', ReportQuery::BALANCE_COLUMNS, (function () use ($query, $row) {
                foreach ($query->cursor() as $r) {
                    yield $row($r);
                }
            })());
        }

        $page = $query->paginate((int) ($validated['page']['size'] ?? 25), ['*'], 'page[number]', (int) ($validated['page']['number'] ?? 1))->through($row);

        return ReportRowResource::page($page, $request);
    }

    private static function wantsCsv(Request $request): bool
    {
        if ($request->getAcceptableContentTypes() === []) {
            return false; // no Accept header: JSON
        }
        $type = $request->prefers(['application/json', 'text/csv']);
        abort_if($type === null, 406, 'Supported: application/json, text/csv.');

        return $type === 'text/csv';
    }

    /**
     * RFC 4180, UTF-8, header row. Text cells starting with = + - @ get a leading ' (CSV injection, API_GUIDELINES §6).
     *
     * @param  list<string>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    private static function csv(string $name, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $v): mixed => match (true) {
                    is_float($v) => number_format($v, 1, '.', ''),
                    is_string($v) && preg_match('/^[=+\-@]/', $v) === 1 => "'".$v,
                    default => $v,
                }, array_values($row)), escape: '');
            }
            fclose($out);
        }, "{$name}-".now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
