<?php

declare(strict_types=1);

namespace App\Enums;

enum AccrualFrequency: string
{
    case Yearly = 'yearly';
    case Monthly = 'monthly';
}
