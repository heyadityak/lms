'use client';

import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import {
    Button,
    Calendar,
    Col,
    Empty,
    Radio,
    Row,
    Select,
    Skeleton,
    Space,
    Typography,
} from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { useHolidays } from '@/lib/queries';
import { PageTitle, ProblemAlert, fmtDate } from './ui';

const MIN_YEAR = 2000;
const MAX_YEAR = 2100;

/** Holidays (UI_GUIDELINES S7): leave-year list and month calendar. Weekend shading is phase 15. */
export function HolidaysView() {
    const [year, setYear] = useState(() => clampYear(dayjs().year()));
    const [cursor, setCursor] = useState(() => dayjs());
    const holidays = useHolidays(true, year);
    const list = [...(holidays.data ?? [])].sort((a, b) => a.date.localeCompare(b.date));
    const byDate = new Map<string, string[]>();
    for (const h of list) {
        const names = byDate.get(h.date) ?? [];
        names.push(h.name);
        byDate.set(h.date, names);
    }

    const goYear = (next: number) => {
        const y = clampYear(next);
        setYear(y);
        setCursor((c) => c.year(y));
    };

    const onCursor = (date: Dayjs) => {
        setCursor((current) => (current.isSame(date, 'day') ? current : date));
        const y = date.year();
        if (y >= MIN_YEAR && y <= MAX_YEAR) setYear((current) => (current === y ? current : y));
    };

    return (
        <>
            <PageTitle>Holidays</PageTitle>
            <Space style={{ marginBottom: 24 }} align="center">
                <span>Leave year</span>
                <Button
                    icon={<LeftOutlined />}
                    aria-label="Previous leave year"
                    disabled={year <= MIN_YEAR}
                    onClick={() => goYear(year - 1)}
                />
                <span
                    aria-live="polite"
                    style={{ minWidth: 48, textAlign: 'center', fontWeight: 600 }}
                >
                    {year}
                </span>
                <Button
                    icon={<RightOutlined />}
                    aria-label="Next leave year"
                    disabled={year >= MAX_YEAR}
                    onClick={() => goYear(year + 1)}
                />
            </Space>
            {holidays.isError && (
                <ProblemAlert error={holidays.error} onRetry={() => holidays.refetch()} />
            )}
            <Row gutter={[30, 30]}>
                <Col xs={24} lg={16}>
                    <Calendar
                        className="lms-holiday-calendar"
                        value={cursor}
                        onChange={onCursor}
                        onPanelChange={onCursor}
                        headerRender={({ value, type, onChange, onTypeChange }) => (
                            <HolidayCalendarHeader
                                value={value}
                                type={type}
                                onChange={onChange}
                                onTypeChange={onTypeChange}
                            />
                        )}
                        cellRender={(date, info) => {
                            if (info.type !== 'date') return info.originNode;
                            const names = byDate.get(date.format('YYYY-MM-DD'));
                            if (!names?.length) return info.originNode;
                            return (
                                <>
                                    {info.originNode}
                                    <div
                                        style={{
                                            fontSize: 12,
                                            lineHeight: 1.3,
                                            whiteSpace: 'normal',
                                        }}
                                    >
                                        {names.join(', ')}
                                    </div>
                                </>
                            );
                        }}
                    />
                </Col>
                <Col xs={24} lg={8}>
                    <Typography.Title level={2} style={{ fontSize: 16 }}>
                        Dates
                    </Typography.Title>
                    {holidays.isLoading ? (
                        <Skeleton active />
                    ) : list.length === 0 ? (
                        <Empty description={`No holidays in ${year}`} />
                    ) : (
                        <div
                            role="region"
                            aria-label={`Holidays in ${year}`}
                            tabIndex={0}
                            style={{ maxHeight: 480, overflowY: 'auto' }}
                        >
                            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                                {list.map((h) => (
                                    <li key={h.id} style={{ padding: '8px 0' }}>
                                        <strong>{fmtDate(h.date)}</strong>
                                        <div>{h.name}</div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Col>
            </Row>
        </>
    );
}

function clampYear(year: number): number {
    return Math.min(MAX_YEAR, Math.max(MIN_YEAR, year));
}

const YEARS = Array.from({ length: MAX_YEAR - MIN_YEAR + 1 }, (_, i) => {
    const year = MIN_YEAR + i;
    return { value: year, label: String(year) };
});

const MONTHS = Array.from({ length: 12 }, (_, month) => ({
    value: month,
    label: dayjs().month(month).format('MMMM'),
}));

/** Month and year controls with visible labels. The stock header selects are unlabeled. */
function HolidayCalendarHeader({
    value,
    type,
    onChange,
    onTypeChange,
}: {
    value: Dayjs;
    type: 'month' | 'year';
    onChange: (date: Dayjs) => void;
    onTypeChange: (type: 'month' | 'year') => void;
}) {
    return (
        <Space wrap style={{ display: 'flex', justifyContent: 'flex-end', padding: '8px 0' }}>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                Year
                <Select
                    id="holiday-calendar-year"
                    value={value.year()}
                    options={YEARS}
                    onChange={(year) => onChange(value.year(year))}
                    style={{ width: 96 }}
                />
            </label>
            {type === 'month' && (
                <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    Month
                    <Select
                        id="holiday-calendar-month"
                        value={value.month()}
                        options={MONTHS}
                        onChange={(month) => onChange(value.month(month))}
                        style={{ width: 140 }}
                    />
                </label>
            )}
            <Radio.Group
                aria-label="Calendar view"
                value={type}
                onChange={(event) => onTypeChange(event.target.value)}
            >
                <Radio.Button value="month">Month</Radio.Button>
                <Radio.Button value="year">Year</Radio.Button>
            </Radio.Group>
        </Space>
    );
}
