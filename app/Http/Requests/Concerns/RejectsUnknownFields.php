<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/** openapi `additionalProperties: false` (+ `minProperties: 1` on PATCH): unknown or no body fields → 422 VALIDATION_FAILED. */
trait RejectsUnknownFields
{
    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $body = $this->getInputSource()->all();
            foreach (array_diff(array_keys($body), array_keys($this->rules())) as $key) {
                $validator->errors()->add((string) $key, "The {$key} field is not allowed.");
            }
            if ($body === [] && $this->isMethod('PATCH')) {
                $validator->errors()->add('body', 'At least one field is required.');
            }
        }];
    }
}
