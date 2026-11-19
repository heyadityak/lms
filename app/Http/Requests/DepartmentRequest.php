<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Department;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** openapi `DepartmentWrite` (POST and PATCH both send code + name). */
final class DepartmentRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'create' : 'update', Department::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', new PlainText, Rule::unique('departments', 'code')->ignore($this->route('department'))],
            'name' => ['required', 'string', 'max:100', new PlainText],
        ];
    }
}
