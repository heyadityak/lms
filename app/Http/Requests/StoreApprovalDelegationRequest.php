<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

final class StoreApprovalDelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ApprovalDelegation::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delegate_id' => ['required', 'string', 'ulid', 'exists:users,public_id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
