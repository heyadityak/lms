<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A missing/short comment is 422 COMMENT_REQUIRED from RejectLeaveRequest, not a generic VALIDATION_FAILED. */
final class RejectLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('leaveRequest'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:1000']];
    }
}
