<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class CancelLeaveRequestRequest extends FormRequest
{
    /** Response, not bool, so a denyAsNotFound from the policy stays 404. */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('cancel', $this->route('leaveRequest'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:1000', new PlainText]];
    }
}
