<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/** Profile/role write refused for a federated (LDAP/AD) user or by the provider. */
final class IdpReadOnly extends DomainException
{
    public function __construct(string $detail = 'This user is managed by an external directory and cannot be changed here.', ?Throwable $previous = null)
    {
        parent::__construct($detail, 'IDP_READ_ONLY', 422, previous: $previous);
    }
}
