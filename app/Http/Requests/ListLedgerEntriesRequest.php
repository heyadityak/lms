<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /me/ledger-entries and /users/{id}/ledger-entries query; visibility is checked by the controller. */
final class ListLedgerEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:year,leave_type'],
            'filter.year' => ['sometimes', 'integer', 'between:2000,2100'],
            'filter.leave_type' => ['sometimes', 'string', 'size:26'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'between:1,10000'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
