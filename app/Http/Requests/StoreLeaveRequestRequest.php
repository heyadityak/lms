<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DayPart;
use App\Models\LeaveRequest;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Shapes + V9 only (also the preview body, D-43); business rules V1–V8 are LeaveRequestValidator's. Client-sent `days` is ignored (§3.3.6). */
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
            'hours' => ['sometimes', 'nullable', 'integer', 'between:1,8'],
            'reason' => ['required', 'string', 'min:3', 'max:1000', new PlainText],
            'handover_note' => ['nullable', 'string', 'max:2000', new PlainText],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('hours')) {
                return;
            }
            $part = DayPart::tryFrom((string) $this->input('day_part'));
            if ($part instanceof DayPart && $part->isHalf()) {
                $validator->errors()->add('hours', 'Hours cannot be combined with a half day.');
            }
            if ((string) $this->input('start_date') !== (string) $this->input('end_date')) {
                $validator->errors()->add('hours', 'Hourly leave must start and end on the same date.');
            }
        }];
    }
}
