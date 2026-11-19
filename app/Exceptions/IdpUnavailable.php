<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/** Keycloak Admin API unreachable, timed out or 5xx; nothing was written locally (D-32). */
final class IdpUnavailable extends DomainException
{
    public function __construct(string $detail = 'The identity provider is unavailable. Try again later.', int $retryAfter = 30, ?Throwable $previous = null)
    {
        parent::__construct($detail, 'IDP_UNAVAILABLE', 503, headers: ['Retry-After' => $retryAfter], previous: $previous);
    }
}
