// @vitest-environment jsdom
import { render } from '@testing-library/react';
import { App } from 'antd';
import dayjs from 'dayjs';
import { describe, expect, it, vi } from 'vitest';
import { HolidaysView } from './HolidaysView';

vi.mock('@/lib/queries', () => ({
    useHolidays: () => ({
        data: [],
        isError: false,
        isLoading: false,
        error: null,
        refetch: () => Promise.resolve(),
    }),
}));

describe('HolidaysView', () => {
    it('selects today, not the first of the month', () => {
        const { container } = render(
            <App>
                <HolidaysView />
            </App>,
        );
        const selected = container.querySelector('.ant-picker-cell-selected');
        expect(selected?.getAttribute('title')).toBe(dayjs().format('YYYY-MM-DD'));
    });
});
