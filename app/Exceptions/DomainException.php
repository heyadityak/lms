<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for business-rule failures. Rendered on /api/* as RFC 9457 problem+json by ProblemJsonRenderer:
 * `code` is one of the spec's ProblemCode enum (UPPERCASE), `type` = urn:lms:problem:<code in kebab-case>.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages (always rendered on 422)
     * @param  array<string, string|int>  $headers  e.g. Retry-After
     */
    public function __construct(
        string $detail,
        public readonly string $problemCode,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }
}
