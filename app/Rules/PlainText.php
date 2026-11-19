<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Free text: no control characters except tab, LF, CR (pdo_pgsql truncates at NUL). Invalid UTF-8 fails too. */
final class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[^\P{Cc}\t\n\r]/u', $value) !== 0) {
            $fail('The :attribute field must not contain control characters.');
        }
    }
}
