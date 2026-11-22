<?php

declare(strict_types=1);

namespace App\Enums;

/** Module keys (config/modules.php registry, SYSTEM_DESIGN §8.3). */
enum Module: string
{
    case Holidays = 'holidays';
    case Reports = 'reports';
    case Api = 'api';
    case Notifications = 'notifications';
    case UserManagement = 'user_management';
    case MultiLevel = 'multi_level';
}
