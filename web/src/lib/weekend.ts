/** ISO weekday (1 = Monday … 7 = Sunday) that is not a working day. */
export const WEEKDAYS: { value: number; label: string }[] = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

/**
 * Reject sets the API rejects: duplicates, values outside 1–7, more than 6 days.
 * An empty list is a 7-day working week (D-30). `null` is the reset payload and is not passed here.
 */
export function weekendSelectionError(days: readonly number[]): string | null {
    if (new Set(days).size !== days.length) return 'Each weekday can only be selected once.';
    if (days.length > 6) return 'Leave at least one working day.';
    if (days.some((day) => !Number.isInteger(day) || day < 1 || day > 7)) {
        return 'Weekend days must be Monday through Sunday.';
    }
    return null;
}
