<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A leave business rule (V1–V9, COMMENT_REQUIRED, ...) failed: 422 with the rule's problem code. */
final class LeaveRuleViolation extends DomainException
{
    public function __construct(string $problemCode, string $detail, ?string $field = null)
    {
        parent::__construct($detail, $problemCode, 422, $field === null ? [] : [$field => [$detail]]);
    }
}
