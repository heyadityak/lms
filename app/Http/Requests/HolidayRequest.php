<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Holiday;
use App\Models\Location;
use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** openapi `HolidayWrite`. One holiday per date. */
final class HolidayRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('POST') ? 'create' : 'update', Holiday::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:100', new PlainText],
            'location_id' => ['sometimes', 'nullable', 'string', 'ulid', 'exists:locations,public_id'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $locationId = $this->exists('location_id') && $this->input('location_id') !== null
                ? Location::query()->where('public_id', $this->input('location_id'))->value('id')
                : ($this->exists('location_id') ? null : $this->route('holiday')?->location_id);
            $date = (string) $this->input('date');
            $taken = Holiday::query()
                ->where('date', $date)
                ->when($locationId === null, fn ($query) => $query->whereNull('location_id'), fn ($query) => $query->where('location_id', $locationId))
                ->when($this->route('holiday') !== null, fn ($query) => $query->whereKeyNot($this->route('holiday')->id))
                ->exists();
            if ($taken) {
                $validator->errors()->add('date', 'A holiday already exists on that date for this location.');
            }
        },
            ...$this->rejectUnknownFields(),
        ];
    }
}
