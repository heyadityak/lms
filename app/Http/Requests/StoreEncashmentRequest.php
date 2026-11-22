<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Positive days are stored as a negative encashment ledger row. A note is required. */
final class StoreEncashmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('encash', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'string', Rule::exists('leave_types', 'public_id')],
            'days' => ['required', 'numeric', 'gt:0', 'max:999', 'decimal:0,3'],
            'note' => ['required', 'string', 'min:3', 'max:255', new PlainText],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }
}
