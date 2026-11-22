<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\User;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** openapi `IdentityUserUpdate`. Profile fields go to Keycloak; the rest stay in the LMS. */
final class UpdateIdentityUserRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can('manageIdentity', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'first_name' => ['sometimes', 'string', 'min:1', 'max:100', new PlainText],
            'last_name' => ['sometimes', 'string', 'min:1', 'max:100', new PlainText],
            'department_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:departments,public_id'],
            'manager_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:users,public_id'],
            'date_of_joining' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50', new PlainText, Rule::unique('users', 'employee_code')->ignore($this->route('user'))],
            'designation' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:50', new PlainText],
        ];
    }
}
