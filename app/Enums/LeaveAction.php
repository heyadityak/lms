<?php

declare(strict_types=1);

namespace App\Enums;

enum LeaveAction: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
