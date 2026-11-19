<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/** Keycloak Admin API answered 409 (duplicate username/email). The Keycloak body is never passed through. */
final class IdpConflict extends DomainException
{
    public function __construct(string $detail = 'A user with this username or email already exists in the identity provider.', ?Throwable $previous = null)
    {
        parent::__construct($detail, 'IDP_CONFLICT', 409, previous: $previous);
    }
}
