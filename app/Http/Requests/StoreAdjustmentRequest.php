<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;

/** openapi `AdjustmentCreate` (UC-H5): signed non-zero half-day steps, note required. */
final class StoreAdjustmentRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can('adjustBalance', $this->route('user'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'string', 'ulid', 'exists:leave_types,public_id'],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'days' => ['required', 'numeric', 'between:-9999.5,9999.5', 'multiple_of:0.5', fn (string $attribute, mixed $value, \Closure $fail) => (float) $value === 0.0 && $fail('The days field must not be zero.')],
            'note' => ['required', 'string', 'min:3', 'max:255', new PlainText],
        ];
    }
}
