<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

final class AuditLogController
{
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        $page = AuditLog::query()->with('actor')->orderByDesc('id')->paginate(
            (int) $request->validated('page.size', 25),
            ['*'],
            'page[number]',
            (int) $request->validated('page.number', 1),
        );

        return AuditLogResource::page($page, $request);
    }
}
