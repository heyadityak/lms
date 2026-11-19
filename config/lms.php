<?php

declare(strict_types=1);

// Leave rules defaults from env (SYSTEM_DESIGN §9). Runtime overrides go through App\Support\Settings, never config().
return [
    // ISO weekdays (1 = Mon … 7 = Sun). Default for setting `work.weekend_days` (D-30).
    'weekend_days' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) env('LMS_WEEKEND_DAYS', '6,7'))), 'strlen'))),
    // First month of the leave year, 1–12 (D-26).
    'leave_year_start_month' => (int) env('LEAVE_YEAR_START_MONTH', 1),
    // Seed default for SL max_backdate_days (D-31).
    'sl_max_backdate_days' => (int) env('LMS_SL_MAX_BACKDATE_DAYS', 7),
];
