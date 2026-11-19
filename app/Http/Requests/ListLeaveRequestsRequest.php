<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Query params of leave-request lists (API_GUIDELINES §3): unknown filters → 422. Allowed filters depend on the route. */
final class ListLeaveRequestsRequest extends FormRequest
{
    private const FILTERS = [
        'api.v1.leave-requests.index' => 'status,year,from,to,user,leave_type,department',
        'api.v1.approvals' => 'status,department',
        'api.v1.reports.leave-requests' => 'status,from,to,leave_type,department',
    ];

    public function authorize(): bool
    {
        return true; // visibility is scoped in the query; the inbox authorizes in its controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $statuses = $this->routeIs('api.v1.approvals', 'approvals.index') ? 'in:pending' : 'regex:/^(pending|approved|rejected|cancelled)(,(pending|approved|rejected|cancelled))*$/';

        return [
            'filter' => ['sometimes', 'array:'.(self::FILTERS[$this->route()?->getName()] ?? 'status,year,from,to')],
            'filter.status' => ['sometimes', 'string', $statuses],
            'filter.year' => ['sometimes', 'integer', 'between:2000,2100'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d'],
            'filter.user' => ['sometimes', 'string', 'size:26'],
            'filter.leave_type' => ['sometimes', 'string', 'size:26'],
            'filter.department' => ['sometimes', 'string', 'size:26'],
            'sort' => ['sometimes', 'in:start_date,-start_date,created_at,-created_at,days,-days'],
            'include' => ['sometimes', 'string', 'regex:/^(actions|user|leave_type)(,(actions|user|leave_type))*$/'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'between:1,10000'], // bound keeps OFFSET far from int overflow
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return (array) $this->validated('filter', []);
    }

    public function includes(string $relation): bool
    {
        return in_array($relation, explode(',', (string) $this->validated('include', '')), true);
    }

    public function pageSize(): int
    {
        return (int) ($this->validated('page.size') ?? 25);
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page.number') ?? 1);
    }
}
