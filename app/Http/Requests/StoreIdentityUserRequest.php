<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\User;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** openapi `IdentityUserCreate`. No password field exists. */
final class StoreIdentityUserRequest extends FormRequest
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
            'username' => ['required', 'regex:/^[a-z0-9._-]{3,64}$/'],
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'min:1', 'max:100', new PlainText],
            'last_name' => ['required', 'string', 'min:1', 'max:100', new PlainText],
            'enabled' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'in:employee,manager,hr_admin'],
            'is_admin' => ['sometimes', 'boolean'],
            'department_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:departments,public_id'],
            'manager_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:users,public_id'],
            'date_of_joining' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50', new PlainText, Rule::unique('users', 'employee_code')],
            'designation' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:50', new PlainText],
        ];
    }
}
