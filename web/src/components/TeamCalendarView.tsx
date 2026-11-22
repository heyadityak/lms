'use client';

import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Button, Empty, Form, Space, Table } from 'antd';
import type { TableColumnsType } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { DateRangeField } from './DateRangeField';
import { PageTitle, ProblemAlert, fmtDate, fmtDays } from './ui';

type TeamLeave = Schemas['TeamCalendarEntry'];

interface FilterValues {
    dates?: [Dayjs | null, Dayjs | null] | null;
}

function rowKey(row: TeamLeave): string {
    return `${row.user_name}|${row.leave_type_name}|${row.start_date}|${row.end_date}`;
}

/** Approved leave in the caller's department. The API omits the reason. */
export function TeamCalendarView() {
    const [from, setFrom] = useState(() => dayjs().startOf('month'));
    const [to, setTo] = useState(() => dayjs().endOf('month'));
    const query = { from: from.format('YYYY-MM-DD'), to: to.format('YYYY-MM-DD') };
    const list = useQuery({
        queryKey: ['team-calendar', query],
        queryFn: () => unwrap(api.GET('/team-calendar', { params: { query } })),
        placeholderData: keepPreviousData,
    });

    const columns: TableColumnsType<TeamLeave> = [
        { title: 'Employee', key: 'user', dataIndex: 'user_name' },
        { title: 'Leave type', key: 'type', dataIndex: 'leave_type_name' },
        { title: 'From', key: 'from', dataIndex: 'start_date', render: fmtDate },
        { title: 'To', key: 'to', dataIndex: 'end_date', render: fmtDate },
        { title: 'Days', key: 'days', dataIndex: 'days', align: 'right', render: fmtDays },
    ];

    return (
        <>
            <PageTitle>Team calendar</PageTitle>
            <Form<FilterValues>
                layout="vertical"
                initialValues={{ dates: [from, to] }}
                onFinish={(values) => {
                    const [start, end] = values.dates ?? [];
                    if (!start || !end) return;
                    setFrom(start);
                    setTo(end);
                }}
                style={{ marginBottom: 16 }}
            >
                <Space wrap align="end">
                    <Form.Item name="dates" label="Dates" extra="Format YYYY-MM-DD">
                        <DateRangeField
                            startLabel="From"
                            endLabel="To"
                            format="YYYY-MM-DD"
                            placeholder={['From', 'To']}
                        />
                    </Form.Item>
                    <Button htmlType="submit" style={{ marginBottom: 24 }}>
                        Apply
                    </Button>
                </Space>
            </Form>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            <Table<TeamLeave>
                rowKey={rowKey}
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                pagination={false}
                scroll={{ x: 720 }}
                locale={{
                    emptyText: (
                        <Empty description="No approved leave in your department for these dates" />
                    ),
                }}
            />
        </>
    );
}
