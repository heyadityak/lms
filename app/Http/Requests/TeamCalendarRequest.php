<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Approved leave overlapping [from, to] for the caller's department. */
final class TeamCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $from = CarbonImmutable::parse((string) $this->input('from'));
            $to = CarbonImmutable::parse((string) $this->input('to'));
            if ($from->diffInDays($to) > 365) {
                $validator->errors()->add('to', 'The date range may not be longer than 366 days.');
            }
        }];
    }
}
