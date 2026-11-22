'use client';

import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Empty, Table } from 'antd';
import type { TableColumnsType } from 'antd';
import dayjs from 'dayjs';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { usePager } from '@/lib/table';
import { PageTitle, ProblemAlert } from '../ui';

type AuditLog = Schemas['AuditLog'];

const SUBJECT: Record<AuditLog['subject_type'], string> = {
    department: 'Department',
    leave_type: 'Leave type',
    holiday: 'Holiday',
    location: 'Location',
    leave_quota: 'Leave quota',
    encashment: 'Encashment',
    user: 'User',
};

function changesText(changes: AuditLog['changes']): string {
    const keys = Object.keys(changes);
    return keys.length ? keys.join(', ') : '—';
}

/** Who changed which setting (UC-H15). Newest first. */
export function AuditLogsView() {
    const pager = usePager();
    const query = { 'page[number]': pager.page, 'page[size]': pager.size };
    const list = useQuery({
        queryKey: ['audit-logs', query],
        queryFn: () => unwrap(api.GET('/audit-logs', { params: { query } })),
        placeholderData: keepPreviousData,
    });

    const columns: TableColumnsType<AuditLog> = [
        {
            title: 'When',
            key: 'created_at',
            dataIndex: 'created_at',
            render: (value: string) => dayjs(value).format('DD MMM YYYY HH:mm'),
        },
        { title: 'Actor', key: 'actor', render: (_, row) => row.actor.name },
        {
            title: 'Action',
            key: 'action',
            dataIndex: 'action',
            render: (value: AuditLog['action']) => value[0]?.toUpperCase() + value.slice(1),
        },
        {
            title: 'Subject',
            key: 'subject',
            render: (_, row) =>
                row.subject_id
                    ? `${SUBJECT[row.subject_type]} ${row.subject_id}`
                    : SUBJECT[row.subject_type],
        },
        { title: 'Changes', key: 'changes', render: (_, row) => changesText(row.changes) },
    ];

    return (
        <>
            <PageTitle>Audit log</PageTitle>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            <Table<AuditLog>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                onChange={(page) => pager.apply(page.current, page.pageSize)}
                pagination={pager.pagination(list.data?.meta.total)}
                scroll={{ x: 880 }}
                locale={{ emptyText: <Empty description="No audit entries" /> }}
            />
        </>
    );
}
