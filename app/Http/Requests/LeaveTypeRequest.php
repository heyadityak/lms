<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AccrualFrequency;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\LeaveType;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** openapi `LeaveTypeCreate` (POST) / `LeaveTypeUpdate` (PATCH: no code, code is immutable; is_active toggles). */
final class LeaveTypeRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'create' : 'update', LeaveType::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $days = ['numeric', 'min:0', 'max:9999.5', 'multiple_of:0.5']; // decimal(5,1), Days = multiple of 0.5

        return array_merge($create ? ['code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,10}$/', 'unique:leave_types,code']] : [], [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:100', new PlainText],
            'annual_quota' => [$create ? 'required' : 'sometimes', ...$days],
            'tracks_balance' => [$create ? 'required' : 'sometimes', 'boolean'],
            'carry_forward_cap' => ['sometimes', 'nullable', ...$days],
            'max_balance' => ['sometimes', 'nullable', ...$days],
            'allow_half_day' => ['sometimes', 'boolean'],
            'max_backdate_days' => ['sometimes', 'integer', 'between:0,3650'],
            'sandwich' => ['sometimes', 'boolean'],
            'accrual_frequency' => ['sometimes', Rule::enum(AccrualFrequency::class)],
        ], $create ? [] : ['is_active' => ['sometimes', 'boolean']]);
    }
}
