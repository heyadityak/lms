import dayjs from 'dayjs';
import type { Schemas } from '@/lib/api/client';

export interface DayOff {
    date: string;
    people: { id: string; name: string; status: Schemas['LeaveStatus'] }[];
}

/**
 * Who is off on each working day of [from, from + days): requests covering the date, weekend
 * days (ISO 1 = Mon … 7 = Sun) skipped, dates without anyone dropped.
 */
export function groupTimeOff(
    requests: Schemas['LeaveRequest'][],
    from: string,
    days: number,
    weekendDays: readonly number[],
): DayOff[] {
    const out: DayOff[] = [];
    for (let i = 0; i < days; i++) {
        const d = dayjs(from).add(i, 'day');
        if (weekendDays.includes(d.day() || 7)) continue;
        const date = d.format('YYYY-MM-DD');
        const people = requests
            .filter((r) => r.start_date <= date && r.end_date >= date)
            .map((r) => ({ id: r.id, name: r.user.name, status: r.status }));
        if (people.length) out.push({ date, people });
    }
    return out;
}
