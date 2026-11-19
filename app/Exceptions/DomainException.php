<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for business-rule failures. Rendered as RFC 9457 problem+json on /api/*
 * with a stable machine-readable `code`. Extend it, e.g. InvalidTransition (409, 'invalid_transition').
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'domain_error',
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
