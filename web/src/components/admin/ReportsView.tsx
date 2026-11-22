'use client';

import { keepPreviousData, useQuery } from '@tanstack/react-query';
import {
    Button,
    DatePicker,
    Empty,
    Form,
    InputNumber,
    Segmented,
    Select,
    Space,
    Table,
} from 'antd';
import type { TableColumnsType } from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { downloadCsv } from '@/lib/csv';
import { useAllLeaveTypes, useDepartments } from '@/lib/queries';
import { usePager } from '@/lib/table';
import { PageTitle, ProblemAlert, STATUS_LABEL, StatusTag, fmtDate } from '../ui';

type Status = Schemas['LeaveStatus'];

/** JSON report body. `text/csv` is the download path, not this table. */
function jsonReport<T extends { meta: { total: number } }>(body: T | string): T {
    if (typeof body === 'string') throw new Error('Expected a JSON report');
    return body;
}

const DAY_PART: Record<Schemas['DayPart'], string> = {
    full: 'Full day',
    first_half: 'First half',
    second_half: 'Second half',
};

/** Reports (UC-H7, UC-H8). CSV is the same filters through the BFF with Accept: text/csv. */
export function ReportsView() {
    const [tab, setTab] = useState<'requests' | 'balances'>('requests');
    return (
        <>
            <PageTitle>Reports</PageTitle>
            <Segmented
                aria-label="Report"
                value={tab}
                onChange={(value) => setTab(value as 'requests' | 'balances')}
                options={[
                    { label: 'Leave requests', value: 'requests' },
                    { label: 'Balances', value: 'balances' },
                ]}
                style={{ marginBottom: 24 }}
            />
            {tab === 'requests' ? <LeaveRequestsReport /> : <BalancesReport />}
        </>
    );
}

function FilterSelects() {
    const departments = useDepartments();
    const types = useAllLeaveTypes();
    return (
        <>
            <Form.Item name="department" label="Department">
                <Select
                    allowClear
                    placeholder="Any"
                    style={{ width: 180 }}
                    loading={departments.isFetching}
                    options={(departments.data ?? []).map((row) => ({
                        value: row.id,
                        label: row.name,
                    }))}
                />
            </Form.Item>
            <Form.Item name="leave_type" label="Leave type">
                <Select
                    allowClear
                    placeholder="Any"
                    style={{ width: 180 }}
                    loading={types.isFetching}
                    options={(types.data ?? []).map((row) => ({
                        value: row.id,
                        label: row.name,
                    }))}
                />
            </Form.Item>
        </>
    );
}

interface RequestFilters {
    range?: [Dayjs | null, Dayjs | null] | null;
    department?: string;
    leave_type?: string;
    status?: Status[];
}

function LeaveRequestsReport() {
    const pager = usePager();
    const [filters, setFilters] = useState<RequestFilters>({});
    const [downloadError, setDownloadError] = useState<unknown>(null);
    const [downloading, setDownloading] = useState(false);

    const filterQuery = {
        'filter[from]': filters.range?.[0]?.format('YYYY-MM-DD'),
        'filter[to]': filters.range?.[1]?.format('YYYY-MM-DD'),
        'filter[department]': filters.department || undefined,
        'filter[leave_type]': filters.leave_type || undefined,
        'filter[status]': filters.status?.length ? filters.status.join(',') : undefined,
    };
    const list = useQuery({
        queryKey: ['reports', 'leave-requests', filterQuery, pager.page, pager.size],
        queryFn: async () =>
            jsonReport(
                await unwrap(
                    api.GET('/reports/leave-requests', {
                        params: {
                            query: {
                                ...filterQuery,
                                'page[number]': pager.page,
                                'page[size]': pager.size,
                            },
                        },
                    }),
                ),
            ),
        placeholderData: keepPreviousData,
    });

    const download = async () => {
        setDownloading(true);
        setDownloadError(null);
        try {
            await downloadCsv('reports/leave-requests', filterQuery, 'leave-requests.csv');
        } catch (e) {
            setDownloadError(e);
        } finally {
            setDownloading(false);
        }
    };

    const columns: TableColumnsType<Schemas['LeaveRequestReportRow']> = [
        { title: 'Employee', dataIndex: 'employee', key: 'employee' },
        { title: 'Code', key: 'employee_code', render: (_, row) => row.employee_code ?? '—' },
        { title: 'Department', key: 'department', render: (_, row) => row.department ?? '—' },
        { title: 'Leave type', dataIndex: 'leave_type', key: 'leave_type' },
        { title: 'From', dataIndex: 'start_date', key: 'start_date', render: fmtDate },
        { title: 'To', dataIndex: 'end_date', key: 'end_date', render: fmtDate },
        { title: 'Day part', key: 'day_part', render: (_, row) => DAY_PART[row.day_part] },
        { title: 'Days', dataIndex: 'days', key: 'days', align: 'right' },
        { title: 'Status', key: 'status', render: (_, row) => <StatusTag status={row.status} /> },
        { title: 'Decided by', key: 'decided_by', render: (_, row) => row.decided_by ?? '—' },
    ];

    return (
        <>
            <Form<RequestFilters>
                layout="vertical"
                onFinish={(values) => {
                    setFilters(values);
                    pager.reset();
                }}
                style={{ marginBottom: 16 }}
            >
                <Space wrap align="end">
                    <Form.Item name="range" label="Dates" extra="Format YYYY-MM-DD">
                        <DatePicker.RangePicker format="YYYY-MM-DD" />
                    </Form.Item>
                    <FilterSelects />
                    <Form.Item name="status" label="Status">
                        <Select
                            mode="multiple"
                            allowClear
                            placeholder="Any"
                            style={{ minWidth: 180 }}
                            options={(Object.keys(STATUS_LABEL) as Status[]).map((status) => ({
                                value: status,
                                label: STATUS_LABEL[status],
                            }))}
                        />
                    </Form.Item>
                    <Space style={{ marginBottom: 24 }}>
                        <Button htmlType="submit">Apply filters</Button>
                        <Button onClick={() => void download()} loading={downloading}>
                            Download CSV
                        </Button>
                    </Space>
                </Space>
            </Form>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {downloadError ? <ProblemAlert error={downloadError} /> : null}
            <Table<Schemas['LeaveRequestReportRow']>
                rowKey="request_id"
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                pagination={pager.pagination(list.data?.meta.total)}
                onChange={(p) => pager.apply(p.current, p.pageSize)}
                scroll={{ x: 960 }}
                locale={{ emptyText: <Empty description="No leave requests match" /> }}
            />
        </>
    );
}

interface BalanceFilters {
    year?: number | null;
    department?: string;
    leave_type?: string;
}

function BalancesReport() {
    const pager = usePager();
    const [filters, setFilters] = useState<BalanceFilters>({});
    const [downloadError, setDownloadError] = useState<unknown>(null);
    const [downloading, setDownloading] = useState(false);
    const filterQuery = {
        'filter[year]': filters.year ?? undefined,
        'filter[department]': filters.department || undefined,
        'filter[leave_type]': filters.leave_type || undefined,
    };
    const list = useQuery({
        queryKey: ['reports', 'balances', filterQuery, pager.page, pager.size],
        queryFn: async () =>
            jsonReport(
                await unwrap(
                    api.GET('/reports/balances', {
                        params: {
                            query: {
                                ...filterQuery,
                                'page[number]': pager.page,
                                'page[size]': pager.size,
                            },
                        },
                    }),
                ),
            ),
        placeholderData: keepPreviousData,
    });

    const download = async () => {
        setDownloading(true);
        setDownloadError(null);
        try {
            await downloadCsv('reports/balances', filterQuery, 'balances.csv');
        } catch (e) {
            setDownloadError(e);
        } finally {
            setDownloading(false);
        }
    };

    const columns: TableColumnsType<Schemas['BalanceReportRow']> = [
        { title: 'Employee', dataIndex: 'employee', key: 'employee' },
        { title: 'Code', key: 'employee_code', render: (_, row) => row.employee_code ?? '—' },
        { title: 'Department', key: 'department', render: (_, row) => row.department ?? '—' },
        { title: 'Leave type', dataIndex: 'leave_type', key: 'leave_type' },
        { title: 'Year', dataIndex: 'year', key: 'year' },
        { title: 'Entitled', dataIndex: 'entitled', key: 'entitled', align: 'right' },
        { title: 'Used', dataIndex: 'used', key: 'used', align: 'right' },
        { title: 'Pending', dataIndex: 'pending', key: 'pending', align: 'right' },
        { title: 'Balance', dataIndex: 'balance', key: 'balance', align: 'right' },
        { title: 'Available', dataIndex: 'available', key: 'available', align: 'right' },
    ];

    return (
        <>
            <Form<BalanceFilters>
                layout="vertical"
                onFinish={(values) => {
                    setFilters(values);
                    pager.reset();
                }}
                style={{ marginBottom: 16 }}
            >
                <Space wrap align="end">
                    <Form.Item name="year" label="Leave year">
                        <InputNumber min={2000} max={2100} precision={0} />
                    </Form.Item>
                    <FilterSelects />
                    <Space style={{ marginBottom: 24 }}>
                        <Button htmlType="submit">Apply filters</Button>
                        <Button onClick={() => void download()} loading={downloading}>
                            Download CSV
                        </Button>
                    </Space>
                </Space>
            </Form>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {downloadError ? <ProblemAlert error={downloadError} /> : null}
            <Table<Schemas['BalanceReportRow']>
                rowKey={(row) =>
                    `${row.employee_code ?? row.employee}-${row.leave_type}-${row.year}`
                }
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                pagination={pager.pagination(list.data?.meta.total)}
                onChange={(p) => pager.apply(p.current, p.pageSize)}
                scroll={{ x: 960 }}
                locale={{ emptyText: <Empty description="No balances match" /> }}
            />
        </>
    );
}
