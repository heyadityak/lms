'use client';

import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    App,
    Button,
    DatePicker,
    Descriptions,
    Drawer,
    Empty,
    Form,
    Input,
    InputNumber,
    Modal,
    Popconfirm,
    Select,
    Space,
    Table,
    Typography,
} from 'antd';
import type { TableColumnsType, TableProps } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useAllLeaveTypes, useDepartments } from '@/lib/queries';
import { usePager } from '@/lib/table';
import { useShell } from '../providers';
import { ActiveTag, PageTitle, ProblemAlert, ROLE_LABEL } from '../ui';

type User = Schemas['User'];
type UserSort =
    'name' | '-name' | 'date_of_joining' | '-date_of_joining' | 'created_at' | '-created_at';

interface Filters {
    q?: string;
    department?: string;
    role?: Schemas['Role'];
    active?: 'active' | 'inactive';
}

interface UserValues {
    department_id?: string | null;
    manager_id?: string | null;
    date_of_joining?: Dayjs | null;
    employee_code?: string;
    designation?: string;
    employment_type?: string;
}

interface AdjustValues {
    leave_type_id: string;
    year?: number | null;
    days: number | null;
    note: string;
}

/** HR user fields, balance adjustment, activate/deactivate (UC-H2, UC-H5). Role is read-only. */
export function UsersView() {
    const { user: me } = useShell();
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const departments = useDepartments();
    const pager = usePager();
    const [filters, setFilters] = useState<Filters>({});
    const [sort, setSort] = useState<UserSort>('name');
    const [editing, setEditing] = useState<User | null>(null);
    const [adjusting, setAdjusting] = useState<User | null>(null);
    const [actionError, setActionError] = useState<unknown>(null);

    const query = {
        'filter[q]': filters.q?.trim() || undefined,
        'filter[department]': filters.department || undefined,
        'filter[role]': filters.role,
        'filter[is_active]':
            filters.active === 'active' ? true : filters.active === 'inactive' ? false : undefined,
        sort,
        'page[number]': pager.page,
        'page[size]': pager.size,
    };
    const list = useQuery({
        queryKey: ['users', query],
        queryFn: () => unwrap(api.GET('/users', { params: { query } })),
        placeholderData: keepPreviousData,
    });

    const setActive = async (row: User, active: boolean) => {
        setActionError(null);
        try {
            await unwrap(
                active
                    ? api.POST('/users/{userId}/activate', { params: { path: { userId: row.id } } })
                    : api.POST('/users/{userId}/deactivate', {
                          params: { path: { userId: row.id } },
                      }),
            );
            message.success(active ? `${row.name} can sign in again` : `${row.name} deactivated`);
            await queryClient.invalidateQueries();
        } catch (e) {
            setActionError(e);
        }
    };

    const onTable: TableProps<User>['onChange'] = (p, _f, sorter) => {
        const s = Array.isArray(sorter) ? sorter[0] : sorter;
        const field = s?.columnKey;
        let next: UserSort = 'name';
        if (
            (field === 'name' || field === 'date_of_joining' || field === 'created_at') &&
            s?.order
        ) {
            next = (s.order === 'descend' ? `-${field}` : field) as UserSort;
        }
        if (next !== sort) {
            setSort(next);
            pager.reset();
            return;
        }
        pager.apply(p.current, p.pageSize);
    };

    const sortOrder = (field: 'name' | 'date_of_joining' | 'created_at') =>
        sort === field ? 'ascend' : sort === `-${field}` ? 'descend' : null;

    const columns: TableColumnsType<User> = [
        {
            title: 'Name',
            key: 'name',
            dataIndex: 'name',
            sorter: true,
            sortOrder: sortOrder('name'),
        },
        { title: 'Email', key: 'email', dataIndex: 'email' },
        { title: 'Code', key: 'employee_code', render: (_, row) => row.employee_code ?? '—' },
        { title: 'Department', key: 'department', render: (_, row) => row.department?.name ?? '—' },
        { title: 'Role', key: 'role', render: (_, row) => ROLE_LABEL[row.role] },
        {
            title: 'Status',
            key: 'status',
            render: (_, row) => <ActiveTag active={row.is_active} />,
        },
        {
            title: 'Action',
            key: 'action',
            render: (_, row) => (
                <Space wrap>
                    <Button
                        type="link"
                        onClick={() => setEditing(row)}
                        aria-label={`Edit ${row.name}`}
                    >
                        Edit
                    </Button>
                    <Button
                        type="link"
                        onClick={() => setAdjusting(row)}
                        aria-label={`Adjust balance for ${row.name}`}
                    >
                        Adjust balance
                    </Button>
                    {row.id === me.id ? (
                        <Typography.Text type="secondary">This is you</Typography.Text>
                    ) : (
                        <Popconfirm
                            title={
                                row.is_active ? `Deactivate ${row.name}?` : `Activate ${row.name}?`
                            }
                            description={
                                row.is_active
                                    ? 'They cannot sign in. Pending leave requests are cancelled.'
                                    : 'They can sign in again.'
                            }
                            okText={row.is_active ? 'Deactivate' : 'Activate'}
                            okButtonProps={{ danger: row.is_active }}
                            cancelText="Back"
                            onConfirm={() => setActive(row, !row.is_active)}
                        >
                            <Button
                                type="link"
                                danger={row.is_active}
                                aria-label={`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`}
                            >
                                {row.is_active ? 'Deactivate' : 'Activate'}
                            </Button>
                        </Popconfirm>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageTitle>Users</PageTitle>
            <Form<Filters>
                layout="vertical"
                onFinish={(values) => {
                    setFilters(values);
                    pager.reset();
                }}
                style={{ marginBottom: 16 }}
            >
                <Space wrap align="end">
                    <Form.Item name="q" label="Search">
                        <Input
                            allowClear
                            placeholder="Name, email or code"
                            style={{ width: 220 }}
                        />
                    </Form.Item>
                    <Form.Item name="department" label="Department">
                        <Select
                            allowClear
                            placeholder="Any"
                            style={{ width: 180 }}
                            loading={departments.isFetching}
                            options={(departments.data ?? []).map((d) => ({
                                value: d.id,
                                label: d.name,
                            }))}
                        />
                    </Form.Item>
                    <Form.Item name="role" label="Role">
                        <Select
                            allowClear
                            placeholder="Any"
                            style={{ width: 140 }}
                            options={(Object.keys(ROLE_LABEL) as Schemas['Role'][]).map((role) => ({
                                value: role,
                                label: ROLE_LABEL[role],
                            }))}
                        />
                    </Form.Item>
                    <Form.Item name="active" label="Status">
                        <Select
                            allowClear
                            placeholder="Any"
                            style={{ width: 140 }}
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ]}
                        />
                    </Form.Item>
                    <Button htmlType="submit" style={{ marginBottom: 24 }}>
                        Apply filters
                    </Button>
                </Space>
            </Form>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {actionError ? <ProblemAlert error={actionError} /> : null}
            <Table<User>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                onChange={onTable}
                pagination={pager.pagination(list.data?.meta.total)}
                scroll={{ x: 960 }}
                locale={{ emptyText: <Empty description="No users match" /> }}
            />
            {editing && (
                <UserEditor
                    user={editing}
                    onClose={() => setEditing(null)}
                    onSaved={async () => {
                        message.success(`Saved ${editing.name}`);
                        setEditing(null);
                        await queryClient.invalidateQueries();
                    }}
                />
            )}
            {adjusting && (
                <AdjustModal
                    user={adjusting}
                    onClose={() => setAdjusting(null)}
                    onSaved={async () => {
                        message.success(`Adjusted ${adjusting.name}'s balance`);
                        setAdjusting(null);
                        await queryClient.invalidateQueries();
                    }}
                />
            )}
        </>
    );
}

function blank(value: string | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}

function UserEditor({
    user,
    onClose,
    onSaved,
}: {
    user: User;
    onClose: () => void;
    onSaved: () => Promise<void>;
}) {
    const [form] = Form.useForm<UserValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const departments = useDepartments();

    const onFinish = async (values: UserValues) => {
        // Role, name and email come from Keycloak. Sending `role` is 422 ROLE_READ_ONLY.
        const body: Schemas['UserUpdate'] = {
            department_id: values.department_id ?? null,
            manager_id: values.manager_id ?? null,
            employee_code: blank(values.employee_code),
            designation: blank(values.designation),
            employment_type: blank(values.employment_type),
        };
        if (values.date_of_joining) {
            body.date_of_joining = values.date_of_joining.format('YYYY-MM-DD');
        }
        setSaving(true);
        setError(null);
        try {
            await unwrap(
                api.PATCH('/users/{userId}', { params: { path: { userId: user.id } }, body }),
            );
            await onSaved();
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Drawer
            open
            size={480}
            title={`Edit ${user.name}`}
            onClose={onClose}
            extra={
                <Button type="primary" loading={saving} onClick={() => form.submit()}>
                    Save
                </Button>
            }
        >
            <Descriptions
                column={1}
                style={{ marginBottom: 24 }}
                items={[
                    { key: 'name', label: 'Name', children: user.name },
                    { key: 'email', label: 'Email', children: user.email },
                    { key: 'role', label: 'Role', children: ROLE_LABEL[user.role] },
                    {
                        key: 'admin',
                        label: 'Administrator',
                        children: user.is_admin ? 'Yes' : 'No',
                    },
                ]}
            />
            <Typography.Paragraph type="secondary">
                Name, email and role are managed in the identity provider.
            </Typography.Paragraph>
            {error ? <ProblemAlert error={error} /> : null}
            <Form<UserValues>
                form={form}
                layout="vertical"
                onFinish={onFinish}
                initialValues={{
                    department_id: user.department?.id ?? null,
                    manager_id: user.manager?.id ?? null,
                    date_of_joining: user.date_of_joining ? dayjs(user.date_of_joining) : null,
                    employee_code: user.employee_code ?? '',
                    designation: user.designation ?? '',
                    employment_type: user.employment_type ?? '',
                }}
            >
                <Form.Item name="department_id" label="Department">
                    <Select
                        allowClear
                        placeholder="No department"
                        loading={departments.isFetching}
                        options={(departments.data ?? []).map((d) => ({
                            value: d.id,
                            label: d.name,
                        }))}
                    />
                </Form.Item>
                <Form.Item name="manager_id" label="Manager">
                    <ManagerSelect user={user} />
                </Form.Item>
                <Form.Item
                    name="date_of_joining"
                    label="Date of joining"
                    extra="Format YYYY-MM-DD. The first date set grants a prorated balance. Leave this blank to keep the current date."
                >
                    <DatePicker format="YYYY-MM-DD" style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item name="employee_code" label="Employee code">
                    <Input maxLength={50} />
                </Form.Item>
                <Form.Item name="designation" label="Designation">
                    <Input maxLength={100} />
                </Form.Item>
                <Form.Item name="employment_type" label="Employment type">
                    <Input maxLength={50} />
                </Form.Item>
            </Form>
        </Drawer>
    );
}

function ManagerSelect({ user }: { user: User }) {
    const [q, setQ] = useState('');
    const list = useQuery({
        queryKey: ['users', 'picker', q],
        queryFn: () =>
            unwrap(
                api.GET('/users', {
                    params: {
                        query: { 'filter[q]': q || undefined, 'page[size]': 25, sort: 'name' },
                    },
                }),
            ),
    });
    const options = (list.data?.data ?? [])
        .filter((row) => row.id !== user.id)
        .map((row) => ({ value: row.id, label: `${row.name} (${ROLE_LABEL[row.role]})` }));
    if (user.manager && !options.some((option) => option.value === user.manager?.id)) {
        options.unshift({ value: user.manager.id, label: user.manager.name });
    }
    return (
        <Select
            allowClear
            showSearch
            filterOption={false}
            onSearch={setQ}
            options={options}
            loading={list.isFetching}
            placeholder="Search by name"
        />
    );
}

function AdjustModal({
    user,
    onClose,
    onSaved,
}: {
    user: User;
    onClose: () => void;
    onSaved: () => Promise<void>;
}) {
    const [form] = Form.useForm<AdjustValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const types = useAllLeaveTypes();
    const balances = useQuery({
        queryKey: ['users', user.id, 'balances'],
        queryFn: () =>
            unwrap(api.GET('/users/{userId}/balances', { params: { path: { userId: user.id } } })),
    });

    const onFinish = async (values: AdjustValues) => {
        const body: Schemas['AdjustmentCreate'] = {
            leave_type_id: values.leave_type_id,
            days: values.days ?? 0,
            note: values.note.trim(),
        };
        if (values.year) body.year = values.year;
        setSaving(true);
        setError(null);
        try {
            await unwrap(
                api.POST('/users/{userId}/ledger-entries', {
                    params: { path: { userId: user.id } },
                    body,
                }),
            );
            await onSaved();
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            title={`Adjust balance for ${user.name}`}
            okText="Save adjustment"
            confirmLoading={saving}
            onOk={() => form.submit()}
            onCancel={onClose}
            destroyOnHidden
        >
            {balances.isError && <ProblemAlert error={balances.error} />}
            {balances.data && balances.data.data.length > 0 && (
                <ul>
                    {balances.data.data.map((row) => (
                        <li key={row.leave_type.id}>
                            {row.leave_type.name}: available {row.available}, balance {row.balance},
                            pending {row.pending}
                        </li>
                    ))}
                </ul>
            )}
            {error ? <ProblemAlert error={error} /> : null}
            <Form<AdjustValues> form={form} layout="vertical" onFinish={onFinish}>
                <Form.Item
                    name="leave_type_id"
                    label="Leave type"
                    rules={[{ required: true, message: 'Choose a leave type' }]}
                >
                    <Select
                        loading={types.isFetching}
                        options={(types.data ?? []).map((type) => ({
                            value: type.id,
                            label: `${type.name} (${type.code})`,
                        }))}
                    />
                </Form.Item>
                <Form.Item
                    name="year"
                    label="Leave year"
                    extra="Leave blank for the current leave year."
                >
                    <InputNumber min={2000} max={2100} precision={0} style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item
                    name="days"
                    label="Days"
                    extra="Positive adds days. Negative removes them. Multiple of 0.5, not zero."
                    rules={[
                        {
                            validator: async (_, value: number | null | undefined) => {
                                if (value == null || value === 0) {
                                    throw new Error('Enter a non-zero number of days');
                                }
                                if (Math.abs(value * 2 - Math.round(value * 2)) > 1e-9) {
                                    throw new Error('Days must be a multiple of 0.5');
                                }
                            },
                        },
                    ]}
                >
                    <InputNumber step={0.5} style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item
                    name="note"
                    label="Note"
                    rules={[
                        { required: true, whitespace: true, message: 'A note is required' },
                        { min: 3, message: 'At least 3 characters' },
                    ]}
                >
                    <Input.TextArea rows={3} maxLength={255} showCount />
                </Form.Item>
            </Form>
        </Modal>
    );
}
