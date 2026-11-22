<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\DomainException;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\User;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** openapi `UserUpdate`: HR-owned fields only. `role` → 422 ROLE_READ_ONLY (Keycloak owns roles, D-20); name/email → VALIDATION_FAILED. */
final class UpdateUserRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:departments,public_id'],
            'manager_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:users,public_id'],
            'date_of_joining' => ['sometimes', 'date_format:Y-m-d'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50', new PlainText, Rule::unique('users', 'employee_code')->ignore($this->route('user'))],
            'designation' => ['sometimes', 'nullable', 'string', 'max:100', new PlainText],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:50', new PlainText],
            'location_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:locations,public_id'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->getInputSource()->has('role')) {
                    throw new DomainException('Roles are managed in Keycloak (PUT /admin/users/{id}/roles).', 'ROLE_READ_ONLY', 422, ['role' => ['The role is read-only here.']]);
                }
            },
            ...$this->rejectUnknownFields(),
        ];
    }
}
