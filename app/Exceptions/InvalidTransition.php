<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Leave status change not allowed from the current status (LeaveStatus::canTransitionTo). */
final class InvalidTransition extends DomainException
{
    public function __construct(string $detail)
    {
        parent::__construct($detail, 'INVALID_TRANSITION', 409);
    }
}
