'use client';

import { useQuery } from '@tanstack/react-query';
import { Button, Col, Row } from 'antd';
import dayjs from 'dayjs';
import { useRouter } from 'next/navigation';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { useBalances, useHolidays, usePendingApprovalCount, useWorkingWeek } from '@/lib/queries';
import { DEFAULT_WEEKEND_DAYS, TEAM_TIME_OFF_DAYS, groupTimeOff } from '@/lib/timeoff';
import { BalanceBreakdown, sum } from './BalanceBreakdown';
import { CarouselCard } from './CarouselCard';
import { useShell } from './providers';
import {
    InitialsAvatar,
    PageTitle,
    ProblemAlert,
    StatCard,
    StatusTag,
    fmtDate,
    isApprover,
} from './ui';

type Holiday = Schemas['Holiday'];

/** Dashboard (UI_GUIDELINES S2). No absent-alert, no employee-wide calendar (UC-E13). */
export function DashboardView() {
    const { user, modules } = useShell();
    const router = useRouter();
    const approver = isApprover(user.role);
    const holidaysOn = modules.includes('holidays');

    const balances = useBalances();
    const pending = usePendingApprovalCount(approver);
    const holidays = useHolidays(holidaysOn);
    const week = useWorkingWeek(approver);
    const from = dayjs().format('YYYY-MM-DD');
    const to = dayjs()
        .add(TEAM_TIME_OFF_DAYS - 1, 'day')
        .format('YYYY-MM-DD');
    const timeOff = useQuery({
        queryKey: ['team-time-off', from, to],
        enabled: approver,
        queryFn: () =>
            unwrap(
                api.GET('/leave-requests', {
                    params: {
                        query: {
                            'filter[status]': 'approved,pending',
                            'filter[from]': from,
                            'filter[to]': to,
                            include: 'user',
                            sort: 'start_date',
                            'page[size]': 100,
                        },
                    },
                }),
            ),
    });

    const b = balances.data?.data ?? [];
    // Default until the working week loads, and again if that request fails, so the card does not jump.
    const weekendDays =
        week.isSuccess && Array.isArray(week.data.weekend_days)
            ? week.data.weekend_days
            : DEFAULT_WEEKEND_DAYS;
    const days = groupTimeOff(timeOff.data?.data ?? [], from, TEAM_TIME_OFF_DAYS, weekendDays);
    const upcoming = (holidays.data ?? [])
        .filter((h) => h.date >= from)
        .sort((a, c) => a.date.localeCompare(c.date));
    const shown = timeOff.data?.data.length ?? 0;
    const total = timeOff.data?.meta.total ?? 0;

    return (
        <>
            <PageTitle>Dashboard</PageTitle>
            {balances.isError && (
                <ProblemAlert error={balances.error} onRetry={() => balances.refetch()} />
            )}
            <Row gutter={[30, 30]}>
                <Col xs={24} md={12} xl={8}>
                    <StatCard
                        compact
                        tone="success"
                        value={sum(b, 'available')}
                        title="Available Leaves"
                        loading={balances.isPending}
                        action={
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <BalanceBreakdown
                                    balances={b}
                                    field="available"
                                    label="Available"
                                />
                                <Button
                                    type="primary"
                                    onClick={() => router.push('/leave-requests/new')}
                                >
                                    Apply Leave
                                </Button>
                            </div>
                        }
                    />
                </Col>
                {approver && (
                    <Col xs={24} md={12} xl={8}>
                        <StatCard
                            compact
                            tone="warning"
                            value={pending.data ?? 0}
                            title="Pending approvals"
                            loading={pending.isPending}
                            action={
                                <Button type="link" onClick={() => router.push('/approvals')}>
                                    View approvals
                                </Button>
                            }
                        />
                    </Col>
                )}
            </Row>
            {(approver || holidaysOn) && (
                <Row gutter={[30, 30]} style={{ marginTop: 30 }}>
                    {approver && (
                        <Col xs={24} lg={holidaysOn ? 12 : 24}>
                            <CarouselCard
                                title="Team Time Off"
                                loading={timeOff.isLoading}
                                error={timeOff.isError ? timeOff.error : undefined}
                                empty="Nobody is scheduled off"
                                pages={days.map((day) => (
                                    <div key={day.date}>
                                        <p style={{ fontWeight: 600, margin: '0 0 8px' }}>
                                            {fmtDate(day.date)}
                                        </p>
                                        <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                                            {day.people.map((p) => (
                                                <li
                                                    key={p.id}
                                                    style={{
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        gap: 8,
                                                        marginTop: 8,
                                                    }}
                                                >
                                                    <InitialsAvatar name={p.name} />
                                                    <span>{p.name}</span>
                                                    <StatusTag status={p.status} />
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            />
                            {total > shown && (
                                <p style={{ marginTop: 8 }}>
                                    Showing {shown} of {total} requests in this window.
                                </p>
                            )}
                        </Col>
                    )}
                    {holidaysOn && (
                        <Col xs={24} lg={approver ? 12 : 24}>
                            <CarouselCard
                                title="Next Holidays"
                                loading={holidays.isLoading}
                                error={holidays.isError ? holidays.error : undefined}
                                empty="No upcoming holidays"
                                pages={upcoming.map((h) => (
                                    <HolidayPage key={h.id} holiday={h} />
                                ))}
                            />
                        </Col>
                    )}
                </Row>
            )}
        </>
    );
}

function HolidayPage({ holiday }: { holiday: Holiday }) {
    return (
        <p style={{ margin: 0 }}>
            <strong>{holiday.name}</strong>
            <br />
            {fmtDate(holiday.date)}
        </p>
    );
}
