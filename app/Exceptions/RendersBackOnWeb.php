<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Web forms: a business-rule failure goes back to the form with errors; /api/* falls through to ProblemJsonRenderer. */
trait RendersBackOnWeb
{
    public function render(Request $request): ?RedirectResponse
    {
        if ($request->is('api', 'api/*') || $request->expectsJson()) {
            return null;
        }

        return back()->withInput()->withErrors($this->errors ?: ['form' => [$this->getMessage()]]);
    }
}
