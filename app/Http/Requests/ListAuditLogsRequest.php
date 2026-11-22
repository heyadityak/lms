<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;

final class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditLog::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'between:1,10000'],
            'page.size' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
