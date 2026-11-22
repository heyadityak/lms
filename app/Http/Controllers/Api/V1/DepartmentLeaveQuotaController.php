<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Settings\ReplaceDepartmentLeaveQuotas;
use App\Http\Requests\ReplaceDepartmentLeaveQuotasRequest;
use App\Models\Department;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use stdClass;

final class DepartmentLeaveQuotaController
{
    public function update(ReplaceDepartmentLeaveQuotasRequest $request, Department $department, ReplaceDepartmentLeaveQuotas $replace): JsonResponse|Response
    {
        $type = LeaveType::query()->where('public_id', $request->validated('leave_type_id'))->firstOrFail();
        $amount = $request->validated('annual_quota');
        $replace->handle($department, $type, $amount === null ? null : (float) $amount, $request->user());
        if ($amount === null) {
            return response()->noContent();
        }

        return response()->json([
            'data' => ['leave_type_id' => $type->public_id, 'annual_quota' => (float) $amount],
            'meta' => new stdClass,
            'links' => new stdClass,
        ]);
    }
}
