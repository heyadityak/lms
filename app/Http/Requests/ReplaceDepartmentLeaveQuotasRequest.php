<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

/** Replace every quota override for one department. hr_admin. */
final class ReplaceDepartmentLeaveQuotasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Department::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'string', 'ulid', 'exists:leave_types,public_id'],
            'annual_quota' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999.5', 'multiple_of:0.5'],
        ];
    }
}
