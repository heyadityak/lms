<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DayPart;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shapes + V9 only; business rules V1–V8 are SubmitLeaveRequest's. Client-sent `days` is ignored (§3.3.6). */
final class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LeaveRequest::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'string', Rule::exists('leave_types', 'public_id')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'day_part' => ['required', Rule::enum(DayPart::class)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'handover_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
