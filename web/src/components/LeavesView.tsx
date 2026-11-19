'use client';

import { FilterOutlined } from '@ant-design/icons';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    App,
    Badge,
    Button,
    Checkbox,
    Col,
    DatePicker,
    Drawer,
    Empty,
    Form,
    Row,
    Select,
    Space,
    Table,
    type TableColumnsType,
} from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { ApiError, browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { useBalances, useLeaveTypes, useMyPendingCount } from '@/lib/queries';
import { useTableQuery } from '@/lib/table';
import { ApplyLeaveModal } from './ApplyLeaveModal';
import { BalanceBreakdown, sum } from './BalanceBreakdown';
import { CommentModal } from './CommentModal';
import { LeaveDetailDrawer } from './LeaveDetailDrawer';
import { useShell } from './providers';
import { PageTitle, ProblemAlert, STATUS_LABEL, StatCard, StatusTag, fmtDate } from './ui';

type LeaveRequest = Schemas['LeaveRequest'];
type Status = Schemas['LeaveStatus'];

interface Filters {
    status?: Status[];
    leave_type?: string;
    range?: [Dayjs | null, Dayjs | null] | null;
}

/** Owner may cancel pending, or approved before it starts (USE_CASES; the server decides). */
const cancellable = (r: LeaveRequest) =>
    r.status === 'pending' || (r.status === 'approved' && dayjs(r.start_date).isAfter(dayjs(), 'day'));

/** Leaves (Figma 484:925): stats, Apply, filters, my requests table, cancel, detail drawer. */
export function LeavesView() {
    const { user } = useShell();
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const table = useTableQuery('-start_date');
    const [filters, setFilters] = useState<Filters>({});
    const [filterOpen, setFilterOpen] = useState(false);
    const [applyOpen, setApplyOpen] = useState(false);
    const [detail, setDetail] = useState<string | null>(null);
    const [cancelling, setCancelling] = useState<LeaveRequest | null>(null);
    const [filterForm] = Form.useForm<Filters>();

    const balances = useBalances();
    const pending = useMyPendingCount();
    const types = useLeaveTypes();
    const query = {
        ...table.query,
        'filter[user]': user.id,
        'filter[status]': filters.status?.length ? filters.status.join(',') : undefined,
        'filter[leave_type]': filters.leave_type || undefined,
        'filter[from]': filters.range?.[0]?.format('YYYY-MM-DD'),
        'filter[to]': filters.range?.[1]?.format('YYYY-MM-DD'),
    };
    const list = useQuery({
        queryKey: ['my-requests', query],
        queryFn: () => unwrap(api.GET('/leave-requests', { params: { query } })),
        placeholderData: keepPreviousData,
    });
    const activeFilters = [filters.status?.length, filters.leave_type, filters.range?.[0] || filters.range?.[1]].filter(
        Boolean,
    ).length;

    const cancel = async (comment: string | null) => {
        if (!cancelling) return;
        try {
            await unwrap(
                api.POST('/leave-requests/{leaveRequestId}/cancel', {
                    params: { path: { leaveRequestId: cancelling.id } },
                    body: { comment },
                }),
            );
            message.success('Leave request cancelled');
        } catch (e) {
            if (e instanceof ApiError && e.status === 409) {
                message.warning('This request can no longer be cancelled.');
            } else {
                message.error(e instanceof Error ? e.message : 'Could not cancel the request');
                if (!(e instanceof ApiError) || e.status >= 500) return; // keep the dialog for a retry
            }
        }
        setCancelling(null);
        await queryClient.invalidateQueries();
    };

    const b = balances.data?.data ?? [];
    const columns: TableColumnsType<LeaveRequest> = [
        { title: 'Leave type', key: 'type', render: (_, r) => r.leave_type.name },
        {
            title: 'From',
            key: 'start_date',
            dataIndex: 'start_date',
            render: fmtDate,
            sorter: true,
            sortOrder: table.sortOrder('start_date'),
            sortDirections: ['descend', 'ascend'],
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
        { title: 'Status', key: 'status', render: (_, r) => <StatusTag status={r.status} /> },
        { title: 'Reason', key: 'reason', dataIndex: 'reason', ellipsis: true },
        { title: 'Approver', key: 'approver', render: (_, r) => r.decided_by?.name ?? '—' },
        {
            title: 'Action',
            key: 'action',
            render: (_, r) =>
                cancellable(r) && (
                    <Button
                        type="link"
                        danger
                        onClick={(e) => {
                            e.stopPropagation();
                            setCancelling(r);
                        }}
                        aria-label={`Cancel ${r.leave_type.name} from ${fmtDate(r.start_date)}`}
                    >
                        Cancel
                    </Button>
                ),
        },
    ];

    return (
        <>
            <PageTitle>Leaves</PageTitle>
            <Row gutter={[30, 30]} style={{ marginBottom: 30 }}>
                <Col xs={24} sm={12} xl={6}>
                    <StatCard
                        compact
                        tone="success"
                        value={sum(b, 'available')}
                        title="Available Leaves"
                        loading={balances.isPending}
                        action={<BalanceBreakdown balances={b} field="available" label="Available" />}
                    />
                </Col>
                <Col xs={24} sm={12} xl={6}>
                    <StatCard
                        compact
                        tone="neutral"
                        value={sum(b, 'entitled')}
                        title="Entitled this year"
                        loading={balances.isPending}
                        action={<BalanceBreakdown balances={b} field="entitled" label="Entitled" />}
                    />
                </Col>
                <Col xs={24} sm={12} xl={6}>
                    <StatCard
                        compact
                        tone="danger"
                        value={sum(b, 'used')}
                        title="Used this year"
                        loading={balances.isPending}
                        action={<BalanceBreakdown balances={b} field="used" label="Used" />}
                    />
                </Col>
                <Col xs={24} sm={12} xl={6}>
                    <StatCard
                        compact
                        tone="warning"
                        value={pending.data ?? 0}
                        title="Pending leave requests"
                        loading={pending.isPending}
                    />
                </Col>
            </Row>
            {balances.isError && <ProblemAlert error={balances.error} onRetry={() => balances.refetch()} />}

            <Space style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 24 }}>
                <Button type="primary" onClick={() => setApplyOpen(true)}>
                    Apply Leave
                </Button>
                <Badge count={activeFilters} size="small">
                    <Button
                        type="primary"
                        icon={<FilterOutlined />}
                        aria-label={activeFilters ? `Filter (${activeFilters} active)` : 'Filter'}
                        onClick={() => setFilterOpen(true)}
                    />
                </Badge>
            </Space>

            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            <Table<LeaveRequest>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                onChange={table.onChange}
                pagination={table.pagination(list.data?.meta.total)}
                scroll={{ x: 900 }}
                locale={{ emptyText: <Empty description="No leave requests" /> }}
                rowClassName={() => 'lms-table-row'}
                onRow={(r) => ({
                    onClick: () => setDetail(r.id),
                    onKeyDown: (e) => {
                        if (e.key === 'Enter' && e.target === e.currentTarget) setDetail(r.id);
                    },
                    tabIndex: 0,
                    'aria-label': `${r.leave_type.name} ${fmtDate(r.start_date)} to ${fmtDate(r.end_date)}, ${STATUS_LABEL[r.status]}`,
                })}
            />

            <Drawer open={filterOpen} onClose={() => setFilterOpen(false)} title="Filter leaves" size={360}>
                <Form<Filters>
                    form={filterForm}
                    layout="vertical"
                    initialValues={filters}
                    onFinish={(v) => {
                        setFilters(v);
                        table.resetPage();
                        setFilterOpen(false);
                    }}
                >
                    <Form.Item name="status" label="Status">
                        <Checkbox.Group
                            options={(Object.keys(STATUS_LABEL) as Status[]).map((s) => ({
                                value: s,
                                label: STATUS_LABEL[s],
                            }))}
                        />
                    </Form.Item>
                    <Form.Item name="leave_type" label="Leave type">
                        <Select
                            allowClear
                            placeholder="All types"
                            options={(types.data ?? []).map((t) => ({ value: t.id, label: t.name }))}
                        />
                    </Form.Item>
                    <Form.Item name="range" label="Dates (overlapping)" extra="Format YYYY-MM-DD">
                        <DatePicker.RangePicker format="YYYY-MM-DD" allowEmpty={[true, true]} style={{ width: '100%' }} />
                    </Form.Item>
                    <Space>
                        <Button type="primary" htmlType="submit">
                            Apply filters
                        </Button>
                        <Button
                            onClick={() => {
                                filterForm.resetFields();
                                filterForm.setFieldsValue({ status: [], leave_type: undefined, range: null });
                                filterForm.submit();
                            }}
                        >
                            Reset
                        </Button>
                    </Space>
                </Form>
            </Drawer>

            <ApplyLeaveModal open={applyOpen} onClose={() => setApplyOpen(false)} />
            <LeaveDetailDrawer id={detail} onClose={() => setDetail(null)} />
            <CommentModal
                open={cancelling !== null}
                title="Cancel this leave request?"
                okText="Cancel request"
                required={false}
                onSubmit={cancel}
                onClose={() => setCancelling(null)}
            />
        </>
    );
}
