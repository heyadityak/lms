'use client';

import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { App, Button, Empty, Popconfirm, Space, Table, type TableColumnsType } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { ApiError, browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { useTableQuery } from '@/lib/table';
import { CommentModal } from './CommentModal';
import { LeaveDetailDrawer } from './LeaveDetailDrawer';
import { InitialsAvatar, PageTitle, ProblemAlert, fmtDate } from './ui';

type LeaveRequest = Schemas['LeaveRequest'];

/** Approvals inbox (UI_GUIDELINES S6): approve (Popconfirm), reject (comment required). */
export function ApprovalsView() {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const table = useTableQuery('start_date');
    const [rejecting, setRejecting] = useState<LeaveRequest | null>(null);
    const [detail, setDetail] = useState<string | null>(null);

    const list = useQuery({
        queryKey: ['approvals', table.query],
        queryFn: () => unwrap(api.GET('/approvals', { params: { query: table.query } })),
        placeholderData: keepPreviousData,
    });

    /** 409 (or 404: no longer visible) = someone already decided; refresh the inbox. */
    const decide = async (call: () => Promise<unknown>, done: string) => {
        try {
            await call();
            message.success(done);
        } catch (e) {
            if (e instanceof ApiError && (e.status === 409 || e.status === 404)) {
                message.warning('Already decided: this request is no longer pending.');
            } else {
                message.error(e instanceof Error ? e.message : 'The action failed');
                if (!(e instanceof ApiError) || e.status >= 500) return false;
            }
        }
        await queryClient.invalidateQueries();
        return true;
    };

    const approve = (r: LeaveRequest) =>
        decide(
            () =>
                unwrap(
                    api.POST('/leave-requests/{leaveRequestId}/approve', {
                        params: { path: { leaveRequestId: r.id } },
                        body: {},
                    }),
                ),
            `Approved ${r.user.name}'s leave`,
        );

    const reject = async (comment: string | null) => {
        if (!rejecting) return;
        const r = rejecting;
        const ok = await decide(
            () =>
                unwrap(
                    api.POST('/leave-requests/{leaveRequestId}/reject', {
                        params: { path: { leaveRequestId: r.id } },
                        body: { comment: comment ?? '' },
                    }),
                ),
            `Rejected ${r.user.name}'s leave`,
        );
        if (ok) setRejecting(null);
    };

    const columns: TableColumnsType<LeaveRequest> = [
        {
            title: 'Employee',
            key: 'user',
            render: (_, r) => (
                <Space>
                    <InitialsAvatar name={r.user.name} />
                    {r.user.name}
                </Space>
            ),
        },
        { title: 'Leave type', key: 'type', render: (_, r) => r.leave_type.name },
        {
            title: 'From',
            key: 'start_date',
            dataIndex: 'start_date',
            render: fmtDate,
            sorter: true,
            sortOrder: table.sortOrder('start_date'),
        },
        { title: 'To', key: 'to', dataIndex: 'end_date', render: fmtDate },
        {
            title: 'Days',
            key: 'days',
            dataIndex: 'days',
            align: 'right',
            sorter: true,
            sortOrder: table.sortOrder('days'),
        },
        { title: 'Reason', key: 'reason', dataIndex: 'reason', ellipsis: true },
        {
            title: 'Applied',
            key: 'created_at',
            dataIndex: 'created_at',
            render: (v: string) => dayjs(v).format('DD MMM YYYY'),
            sorter: true,
            sortOrder: table.sortOrder('created_at'),
        },
        {
            title: 'Action',
            key: 'action',
            render: (_, r) => (
                <Space onClick={(e) => e.stopPropagation()}>
                    <Popconfirm
                        title={`Approve ${r.user.name}'s leave?`}
                        okText="Approve"
                        cancelText="Back"
                        onConfirm={() => approve(r)}
                    >
                        <Button type="primary" size="small">
                            Approve
                        </Button>
                    </Popconfirm>
                    <Button size="small" danger onClick={() => setRejecting(r)}>
                        Reject
                    </Button>
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageTitle>Approvals</PageTitle>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            <Table<LeaveRequest>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                onChange={table.onChange}
                pagination={table.pagination(list.data?.meta.total)}
                scroll={{ x: 1000 }}
                locale={{ emptyText: <Empty description="Nothing waiting for your approval" /> }}
                rowClassName={() => 'lms-table-row'}
                onRow={(r) => ({
                    onClick: () => setDetail(r.id),
                    onKeyDown: (e) => {
                        if (e.key === 'Enter' && e.target === e.currentTarget) setDetail(r.id);
                    },
                    tabIndex: 0,
                })}
            />
            <CommentModal
                open={rejecting !== null}
                title={rejecting ? `Reject ${rejecting.user.name}'s leave?` : 'Reject'}
                okText="Reject"
                required
                onSubmit={reject}
                onClose={() => setRejecting(null)}
            />
            <LeaveDetailDrawer id={detail} onClose={() => setDetail(null)} />
        </>
    );
}
