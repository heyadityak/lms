import { describe, expect, it } from 'vitest';
import type { Schemas } from '@/lib/api/client';
import { groupTimeOff } from './timeoff';

const req = (id: string, name: string, start: string, end: string) =>
    ({ id, user: { id, name }, start_date: start, end_date: end, status: 'approved' }) as Schemas['LeaveRequest'];

describe('groupTimeOff', () => {
    it('groups people per working day and skips weekends and empty days', () => {
        // 2026-11-20 is a Friday.
        const out = groupTimeOff(
            [req('a', 'Alice', '2026-11-20', '2026-11-23'), req('b', 'Bob', '2026-11-23', '2026-11-23')],
            '2026-11-20',
            5,
            [6, 7],
        );
        expect(out.map((d) => [d.date, d.people.map((p) => p.name)])).toEqual([
            ['2026-11-20', ['Alice']],
            ['2026-11-23', ['Alice', 'Bob']],
        ]);
    });
});
