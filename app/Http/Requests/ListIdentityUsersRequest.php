<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** GET /admin/users query (openapi adminListUsers). */
final class ListIdentityUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageIdentity', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:q,enabled,role'],
            'filter.q' => ['sometimes', 'string', 'max:100'],
            'filter.enabled' => ['sometimes', 'in:true,false,1,0'],
            'filter.role' => ['sometimes', 'in:employee,manager,hr_admin,admin'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'between:1,10000'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
