<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Holiday;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** openapi `HolidayWrite`. One holiday per date. */
final class HolidayRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'create' : 'update', Holiday::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($this->route('holiday'))],
            'name' => ['required', 'string', 'max:100', new PlainText],
        ];
    }
}
