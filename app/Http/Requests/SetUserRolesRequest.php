<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** openapi `RoleAssignment`: exactly one base role, optionally plus `admin`. */
final class SetUserRolesRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    public function authorize(): bool
    {
        return $this->user()->can('manageIdentity', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1', 'max:2'],
            'roles.*' => ['string', 'distinct', 'in:employee,manager,hr_admin,admin'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $roles = array_values((array) $this->input('roles', []));
                $base = array_intersect($roles, ['employee', 'manager', 'hr_admin']);
                if (count($base) !== 1) {
                    $validator->errors()->add('roles', 'Choose exactly one base role (employee, manager or hr_admin), and optionally admin.');
                }
            },
            ...$this->rejectUnknownFields(),
        ];
    }
}
