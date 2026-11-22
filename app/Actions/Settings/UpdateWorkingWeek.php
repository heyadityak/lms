<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * UC-H16 (D-30). Writes or deletes `work.weekend_days`. Never touches `leave_requests.days`:
 * day counts are stored at submit time and stay there.
 */
final class UpdateWorkingWeek
{
    public function __construct(private readonly Settings $settings) {}

    /** @param  list<int>|null  $weekendIsoDays  null deletes the override (back to LMS_WEEKEND_DAYS) */
    public function handle(?array $weekendIsoDays, User $actor): void
    {
        if ($weekendIsoDays !== null) {
            $this->assertValid($weekendIsoDays);
        }

        DB::transaction(function () use ($weekendIsoDays, $actor): void {
            if ($weekendIsoDays === null) {
                $this->settings->forget('work.weekend_days');

                return;
            }

            $this->settings->set('work.weekend_days', array_values($weekendIsoDays), $actor);
        });
    }

    /** @param  list<int>  $days */
    private function assertValid(array $days): void
    {
        $unique = array_unique($days);
        $ok = count($days) <= 6 && count($days) === count($unique);
        foreach ($days as $day) {
            $ok = $ok && is_int($day) && $day >= 1 && $day <= 7;
        }

        if (! $ok) {
            throw new DomainException(
                'Weekend days must be distinct ISO weekdays from 1 to 7, with at least one working day left.',
                'VALIDATION_FAILED',
                422,
                ['weekend_days' => ['Choose at most 6 distinct ISO weekdays (1 = Monday … 7 = Sunday).']],
            );
        }
    }
}
