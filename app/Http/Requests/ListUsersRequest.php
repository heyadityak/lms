<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** GET /users query (openapi listUsers): unknown filters → 422. */
final class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:department,is_active,role,q'],
            'filter.department' => ['sometimes', 'string', 'size:26'],
            'filter.is_active' => ['sometimes', 'in:true,false,1,0'],
            'filter.role' => ['sometimes', 'in:employee,manager,hr_admin'],
            'filter.q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'in:name,-name,date_of_joining,-date_of_joining,created_at,-created_at'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'between:1,10000'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
