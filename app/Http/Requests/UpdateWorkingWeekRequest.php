<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

/** openapi `WorkingWeekUpdate`. `null` resets to LMS_WEEKEND_DAYS (D-30). */
final class UpdateWorkingWeekRequest extends FormRequest
{
    use RejectsUnknownFields;

    public function authorize(): bool
    {
        return $this->user()->can('updateWorkingWeek', Setting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'weekend_days' => ['present', 'nullable', 'array', 'max:6'],
            'weekend_days.*' => ['integer', 'distinct', 'between:1,7'],
        ];
    }
}
