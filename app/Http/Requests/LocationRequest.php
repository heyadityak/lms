<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Location;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LocationRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'create' : 'update', Location::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', new PlainText, Rule::unique('locations', 'code')->ignore($this->route('location'))],
            'name' => ['required', 'string', 'max:100', new PlainText],
        ];
    }
}
