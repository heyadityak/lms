'use client';

import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    App,
    Button,
    DatePicker,
    Drawer,
    Empty,
    Form,
    Input,
    Modal,
    Popconfirm,
    Result,
    Select,
    Skeleton,
    Space,
    Switch,
    Table,
    Typography,
} from 'antd';
import type { TableColumnsType } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { ApiError, browserApi as api, unwrap, unwrapEmpty, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useDepartments } from '@/lib/queries';
import { usePager } from '@/lib/table';
import { PageTitle, ProblemAlert, ROLE_LABEL, TONES } from '../ui';

type IdentityUser = Schemas['IdentityUser'];
type Role = Schemas['Role'];

interface Filters {
    q?: string;
    enabled?: 'enabled' | 'disabled';
    role?: Schemas['AssignableRole'];
}

interface CreateValues {
    username: string;
    email: string;
    first_name: string;
    last_name: string;
    enabled: boolean;
    role: Role;
    is_admin: boolean;
    department_id?: string | null;
    manager_id?: string | null;
    date_of_joining?: Dayjs | null;
    employee_code?: string;
    designation?: string;
    employment_type?: string;
}

interface EditValues {
    email: string;
    first_name: string;
    last_name: string;
    department_id?: string | null;
    manager_id?: string | null;
    date_of_joining?: Dayjs | null;
    employee_code?: string;
    designation?: string;
    employment_type?: string;
}

interface RoleValues {
    role: Role;
    is_admin: boolean;
}

const BASE_ROLES: Role[] = ['employee', 'manager', 'hr_admin'];

const ROLE_FILTER: { value: Schemas['AssignableRole']; label: string }[] = [
    ...BASE_ROLES.map((role) => ({ value: role, label: ROLE_LABEL[role] })),
    { value: 'admin', label: 'Administrator' },
];

function isIdpUnavailable(error: unknown): error is ApiError {
    return (
        error instanceof ApiError &&
        error.status === 503 &&
        error.problem.code === 'IDP_UNAVAILABLE'
    );
}

export function identityName(
    user: Pick<IdentityUser, 'first_name' | 'last_name' | 'username'>,
): string {
    const name = [user.first_name, user.last_name].filter(Boolean).join(' ').trim();
    return name || user.username;
}

function blank(value: string | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}

/**
 * Keycloak identity users (UC-U1–U6). No password field: create and reset send email.
 * 503 `IDP_UNAVAILABLE` replaces the page with a retry state.
 */
export function IdentityUsersView({ currentUserId }: { currentUserId: string }) {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const pager = usePager();
    const [filters, setFilters] = useState<Filters>({});
    const [editor, setEditor] = useState<IdentityUser | 'new' | null>(null);
    const [rolesFor, setRolesFor] = useState<IdentityUser | null>(null);
    const [actionError, setActionError] = useState<unknown>(null);

    const query = {
        'filter[q]': filters.q?.trim() || undefined,
        'filter[enabled]':
            filters.enabled === 'enabled'
                ? true
                : filters.enabled === 'disabled'
                  ? false
                  : undefined,
        'filter[role]': filters.role,
        'page[number]': pager.page,
        'page[size]': pager.size,
    };
    const list = useQuery({
        queryKey: ['identity-users', query],
        queryFn: () => unwrap(api.GET('/admin/users', { params: { query } })),
        placeholderData: keepPreviousData,
        retry: (n, e) =>
            n < 2 && !(e instanceof ApiError && e.status < 500) && !isIdpUnavailable(e),
    });

    const refresh = async () => {
        await queryClient.invalidateQueries({ queryKey: ['identity-users'] });
    };

    const idp = isIdpUnavailable(list.error)
        ? list.error
        : isIdpUnavailable(actionError)
          ? actionError
          : null;

    const run = async (work: () => Promise<void>, success: string) => {
        setActionError(null);
        try {
            await work();
            message.success(success);
            await refresh();
        } catch (e) {
            setActionError(e);
        }
    };

    const setEnabled = (row: IdentityUser, enabled: boolean) => {
        if (row.id === currentUserId) return;
        const name = identityName(row);
        void run(
            async () => {
                await unwrap(
                    enabled
                        ? api.POST('/admin/users/{userId}/enable', {
                              params: { path: { userId: row.id } },
                          })
                        : api.POST('/admin/users/{userId}/disable', {
                              params: { path: { userId: row.id } },
                          }),
                );
            },
            enabled ? `${name} can sign in again` : `${name} disabled`,
        );
    };

    const sendReset = (row: IdentityUser) => {
        if (row.federated) return;
        void run(
            async () => {
                await unwrapEmpty(
                    api.POST('/admin/users/{userId}/password-reset', {
                        params: { path: { userId: row.id } },
                    }),
                );
            },
            `Password reset email sent to ${identityName(row)}`,
        );
    };

    const columns: TableColumnsType<IdentityUser> = [
        { title: 'Username', dataIndex: 'username', key: 'username' },
        { title: 'Name', key: 'name', render: (_, row) => identityName(row) },
        { title: 'Email', key: 'email', render: (_, row) => row.email ?? '—' },
        {
            title: 'Role',
            key: 'role',
            render: (_, row) => (row.role ? ROLE_LABEL[row.role] : 'No LMS role'),
        },
        {
            title: 'Administrator',
            key: 'admin',
            render: (_, row) => (row.is_admin ? 'Yes' : 'No'),
        },
        {
            title: 'Sign-in',
            key: 'enabled',
            render: (_, row) => (
                <span
                    style={{
                        ...(row.enabled ? TONES.success : TONES.neutral),
                        display: 'inline-block',
                        minWidth: 88,
                        textAlign: 'center',
                        borderRadius: 4,
                        padding: '2px 8px',
                    }}
                >
                    {row.enabled ? 'Enabled' : 'Disabled'}
                </span>
            ),
        },
        {
            title: 'Account',
            key: 'account',
            render: (_, row) => (row.federated ? 'Directory' : 'Local'),
        },
        {
            title: 'Action',
            key: 'action',
            render: (_, row) => {
                const name = identityName(row);
                const mine = row.id === currentUserId;
                return (
                    <Space wrap>
                        <Button
                            type="link"
                            onClick={() => setEditor(row)}
                            aria-label={`Edit ${name}`}
                        >
                            Edit
                        </Button>
                        {mine ? (
                            <Typography.Text type="secondary">This is you</Typography.Text>
                        ) : (
                            <Button
                                type="link"
                                onClick={() => setRolesFor(row)}
                                aria-label={`Roles for ${name}`}
                            >
                                Roles
                            </Button>
                        )}
                        {mine ? null : (
                            <Popconfirm
                                title={row.enabled ? `Disable ${name}?` : `Enable ${name}?`}
                                description={
                                    row.enabled
                                        ? 'They are signed out and cannot sign in.'
                                        : 'They can sign in again.'
                                }
                                okText={row.enabled ? 'Disable' : 'Enable'}
                                okButtonProps={{ danger: row.enabled }}
                                cancelText="Back"
                                onConfirm={() => setEnabled(row, !row.enabled)}
                            >
                                <Button
                                    type="link"
                                    danger={row.enabled}
                                    aria-label={`${row.enabled ? 'Disable' : 'Enable'} ${name}`}
                                >
                                    {row.enabled ? 'Disable' : 'Enable'}
                                </Button>
                            </Popconfirm>
                        )}
                        {row.federated ? (
                            <Typography.Text type="secondary">
                                Password is in the directory
                            </Typography.Text>
                        ) : (
                            <Popconfirm
                                title={`Send a password reset email to ${name}?`}
                                description="They choose the new password from that email. This screen never sets a password."
                                okText="Send email"
                                cancelText="Back"
                                onConfirm={() => sendReset(row)}
                            >
                                <Button type="link" aria-label={`Send password reset to ${name}`}>
                                    Password reset
                                </Button>
                            </Popconfirm>
                        )}
                    </Space>
                );
            },
        },
    ];

    if (list.isPending) {
        return (
            <>
                <PageTitle>Identity users</PageTitle>
                <Skeleton active />
            </>
        );
    }
    if (idp) {
        return (
            <IdpDown
                error={idp}
                loading={list.isFetching}
                onRetry={() => {
                    setActionError(null);
                    void list.refetch();
                }}
            />
        );
    }

    return (
        <>
            <PageTitle>Identity users</PageTitle>
            <Typography.Paragraph>
                Accounts are created in the identity provider. No password is set here. New users
                and password resets are emails.
            </Typography.Paragraph>
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
                            placeholder="Username, name or email"
                            style={{ width: 240 }}
                        />
                    </Form.Item>
                    <Form.Item name="role" label="Role">
                        <Select
                            allowClear
                            placeholder="Any"
                            style={{ width: 160 }}
                            options={ROLE_FILTER}
                        />
                    </Form.Item>
                    <Form.Item name="enabled" label="Sign-in">
                        <Select
                            allowClear
                            placeholder="Any"
                            style={{ width: 140 }}
                            options={[
                                { value: 'enabled', label: 'Enabled' },
                                { value: 'disabled', label: 'Disabled' },
                            ]}
                        />
                    </Form.Item>
                    <Button htmlType="submit" style={{ marginBottom: 24 }}>
                        Apply filters
                    </Button>
                </Space>
            </Form>
            <Space style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
                <Button type="primary" onClick={() => setEditor('new')}>
                    Add user
                </Button>
            </Space>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {actionError && !isIdpUnavailable(actionError) ? (
                <ProblemAlert error={actionError} />
            ) : null}
            <Table<IdentityUser>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data?.data}
                loading={list.isFetching}
                pagination={pager.pagination(list.data?.meta.total)}
                onChange={(p) => pager.apply(p.current, p.pageSize)}
                scroll={{ x: 1100 }}
                locale={{ emptyText: <Empty description="No identity users match" /> }}
            />
            {editor && (
                <UserEditor
                    target={editor}
                    onClose={() => setEditor(null)}
                    onIdpDown={setActionError}
                    onSaved={async (success) => {
                        message.success(success);
                        setEditor(null);
                        await refresh();
                    }}
                />
            )}
            {rolesFor && (
                <RolesModal
                    user={rolesFor}
                    onClose={() => setRolesFor(null)}
                    onIdpDown={setActionError}
                    onSaved={async () => {
                        message.success(`Roles updated for ${identityName(rolesFor)}`);
                        setRolesFor(null);
                        await refresh();
                    }}
                />
            )}
        </>
    );
}

function IdpDown({
    error,
    onRetry,
    loading,
}: {
    error: ApiError;
    onRetry: () => void;
    loading: boolean;
}) {
    const detail = error.problem.detail ?? 'The identity provider did not respond.';
    const request = error.problem.request_id ? ` Request ${error.problem.request_id}.` : '';
    return (
        <>
            <PageTitle>Identity users</PageTitle>
            <Result
                status="error"
                title={error.problem.title ?? 'Identity provider unavailable'}
                subTitle={`${detail}${request}`}
                extra={
                    <Button type="primary" onClick={onRetry} loading={loading}>
                        Retry
                    </Button>
                }
            />
        </>
    );
}

function UserEditor({
    target,
    onClose,
    onSaved,
    onIdpDown,
}: {
    target: IdentityUser | 'new';
    onClose: () => void;
    onSaved: (success: string) => Promise<void>;
    onIdpDown: (error: unknown) => void;
}) {
    const creating = target === 'new';
    const user = creating ? null : target;
    return (
        <Drawer
            open
            size={520}
            destroyOnHidden
            title={creating ? 'Add user' : `Edit ${identityName(user!)}`}
            onClose={onClose}
        >
            {creating ? (
                <CreateForm onSaved={onSaved} onIdpDown={onIdpDown} />
            ) : (
                <EditForm user={user!} onSaved={onSaved} onIdpDown={onIdpDown} />
            )}
        </Drawer>
    );
}

function CreateForm({
    onSaved,
    onIdpDown,
}: {
    onSaved: (success: string) => Promise<void>;
    onIdpDown: (error: unknown) => void;
}) {
    const [form] = Form.useForm<CreateValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);

    const onFinish = async (values: CreateValues) => {
        const body: Schemas['IdentityUserCreate'] = {
            username: values.username.trim(),
            email: values.email.trim(),
            first_name: values.first_name.trim(),
            last_name: values.last_name.trim(),
            enabled: values.enabled ?? true,
            role: values.role ?? 'employee',
            is_admin: values.is_admin ?? false,
            department_id: values.department_id ?? null,
            manager_id: values.manager_id ?? null,
            date_of_joining: values.date_of_joining
                ? values.date_of_joining.format('YYYY-MM-DD')
                : null,
            employee_code: blank(values.employee_code),
            designation: blank(values.designation),
            employment_type: blank(values.employment_type),
        };
        setSaving(true);
        setError(null);
        try {
            await unwrap(
                api.POST('/admin/users', {
                    params: { header: { 'Idempotency-Key': crypto.randomUUID() } },
                    body,
                }),
            );
            await onSaved(`${body.first_name} ${body.last_name} created. They will get an email.`);
        } catch (e) {
            if (isIdpUnavailable(e)) {
                onIdpDown(e);
                return;
            }
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <Typography.Paragraph>
                No password is set here. They receive an email to choose one and verify the address.
            </Typography.Paragraph>
            {error ? <ProblemAlert error={error} /> : null}
            <Form<CreateValues>
                form={form}
                layout="vertical"
                autoComplete="off"
                initialValues={{ enabled: true, role: 'employee', is_admin: false }}
                onFinish={(values) => void onFinish(values)}
            >
                <ProfileFields />
                <Form.Item name="enabled" label="Sign-in" valuePropName="checked">
                    <Switch checkedChildren="Enabled" unCheckedChildren="Disabled" />
                </Form.Item>
                <Form.Item
                    name="role"
                    label="Role"
                    rules={[{ required: true, message: 'Choose a role' }]}
                >
                    <Select
                        options={BASE_ROLES.map((role) => ({
                            value: role,
                            label: ROLE_LABEL[role],
                        }))}
                    />
                </Form.Item>
                <Form.Item
                    name="is_admin"
                    label="Administrator"
                    valuePropName="checked"
                    extra="Also grants the admin role. Branding, modules, and identity users."
                >
                    <Switch />
                </Form.Item>
                <LmsFields />
                <Button type="primary" htmlType="submit" loading={saving}>
                    Create user
                </Button>
            </Form>
        </>
    );
}

function EditForm({
    user,
    onSaved,
    onIdpDown,
}: {
    user: IdentityUser;
    onSaved: (success: string) => Promise<void>;
    onIdpDown: (error: unknown) => void;
}) {
    const [form] = Form.useForm<EditValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);

    const onFinish = async (values: EditValues) => {
        const body: Schemas['IdentityUserUpdate'] = {
            department_id: values.department_id ?? null,
            manager_id: values.manager_id ?? null,
            date_of_joining: values.date_of_joining
                ? values.date_of_joining.format('YYYY-MM-DD')
                : null,
            employee_code: blank(values.employee_code),
            designation: blank(values.designation),
            employment_type: blank(values.employment_type),
        };
        if (!user.federated) {
            body.email = values.email.trim();
            body.first_name = values.first_name.trim();
            body.last_name = values.last_name.trim();
        }
        setSaving(true);
        setError(null);
        try {
            await unwrap(
                api.PATCH('/admin/users/{userId}', {
                    params: { path: { userId: user.id } },
                    body,
                }),
            );
            await onSaved(`Saved ${identityName(user)}`);
        } catch (e) {
            if (isIdpUnavailable(e)) {
                onIdpDown(e);
                return;
            }
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            {user.federated ? (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    title="Directory account"
                    description="Name and email are read-only. Leave fields in this app can still be edited."
                />
            ) : null}
            <Typography.Paragraph>
                Username {user.username}. Role and sign-in are changed with Roles and Enable or
                Disable, not this form.
            </Typography.Paragraph>
            {error ? <ProblemAlert error={error} /> : null}
            <Form<EditValues>
                form={form}
                layout="vertical"
                onFinish={(values) => void onFinish(values)}
                initialValues={{
                    email: user.email ?? '',
                    first_name: user.first_name ?? '',
                    last_name: user.last_name ?? '',
                    department_id: user.department?.id ?? null,
                    manager_id: user.manager?.id ?? null,
                    date_of_joining: user.date_of_joining ? dayjs(user.date_of_joining) : null,
                    employee_code: user.employee_code ?? '',
                    designation: user.designation ?? '',
                    employment_type: user.employment_type ?? '',
                }}
            >
                <Form.Item
                    name="email"
                    label="Email"
                    rules={
                        user.federated
                            ? []
                            : [{ required: true, type: 'email', message: 'Enter a valid email' }]
                    }
                >
                    <Input maxLength={255} disabled={user.federated} autoComplete="off" />
                </Form.Item>
                <Form.Item
                    name="first_name"
                    label="First name"
                    rules={
                        user.federated
                            ? []
                            : [
                                  {
                                      required: true,
                                      whitespace: true,
                                      message: 'First name is required',
                                  },
                              ]
                    }
                >
                    <Input maxLength={100} disabled={user.federated} />
                </Form.Item>
                <Form.Item
                    name="last_name"
                    label="Last name"
                    rules={
                        user.federated
                            ? []
                            : [
                                  {
                                      required: true,
                                      whitespace: true,
                                      message: 'Last name is required',
                                  },
                              ]
                    }
                >
                    <Input maxLength={100} disabled={user.federated} />
                </Form.Item>
                <LmsFields user={user} />
                <Button type="primary" htmlType="submit" loading={saving}>
                    Save
                </Button>
            </Form>
        </>
    );
}

function ProfileFields() {
    return (
        <>
            <Form.Item
                name="username"
                label="Username"
                rules={[
                    { required: true, message: 'Username is required' },
                    {
                        pattern: /^[a-z0-9._-]{3,64}$/,
                        message:
                            '3–64 characters: lowercase letters, digits, dots, underscores, or hyphens',
                    },
                ]}
            >
                <Input maxLength={64} autoComplete="off" />
            </Form.Item>
            <Form.Item
                name="email"
                label="Email"
                rules={[{ required: true, type: 'email', message: 'Enter a valid email' }]}
            >
                <Input maxLength={255} autoComplete="off" />
            </Form.Item>
            <Form.Item
                name="first_name"
                label="First name"
                rules={[{ required: true, whitespace: true, message: 'First name is required' }]}
            >
                <Input maxLength={100} autoComplete="off" />
            </Form.Item>
            <Form.Item
                name="last_name"
                label="Last name"
                rules={[{ required: true, whitespace: true, message: 'Last name is required' }]}
            >
                <Input maxLength={100} autoComplete="off" />
            </Form.Item>
        </>
    );
}

function LmsFields({ user }: { user?: IdentityUser }) {
    const departments = useDepartments();
    return (
        <>
            <Form.Item name="department_id" label="Department">
                <Select
                    allowClear
                    placeholder="No department"
                    loading={departments.isFetching}
                    options={(departments.data ?? []).map((d) => ({ value: d.id, label: d.name }))}
                />
            </Form.Item>
            <Form.Item name="manager_id" label="Manager">
                <ManagerSelect
                    excludeId={user?.id}
                    selected={
                        user?.manager ? { id: user.manager.id, name: user.manager.name } : null
                    }
                />
            </Form.Item>
            <Form.Item name="date_of_joining" label="Date of joining" extra="Format YYYY-MM-DD.">
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
        </>
    );
}

function ManagerSelect({
    excludeId,
    selected,
}: {
    excludeId?: string;
    selected: { id: string; name: string } | null;
}) {
    const [q, setQ] = useState('');
    const list = useQuery({
        queryKey: ['identity-users', 'picker', q],
        queryFn: () =>
            unwrap(
                api.GET('/admin/users', {
                    params: { query: { 'filter[q]': q || undefined, 'page[size]': 25 } },
                }),
            ),
    });
    const options = (list.data?.data ?? [])
        .filter((row) => row.id !== excludeId)
        .map((row) => ({ value: row.id, label: identityName(row) }));
    if (selected && !options.some((option) => option.value === selected.id)) {
        options.unshift({ value: selected.id, label: selected.name });
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

function RolesModal({
    user,
    onClose,
    onSaved,
    onIdpDown,
}: {
    user: IdentityUser;
    onClose: () => void;
    onSaved: () => Promise<void>;
    onIdpDown: (error: unknown) => void;
}) {
    const [form] = Form.useForm<RoleValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const name = identityName(user);

    const onFinish = async (values: RoleValues) => {
        const roles: Schemas['AssignableRole'][] = [values.role];
        if (values.is_admin) roles.push('admin');
        setSaving(true);
        setError(null);
        try {
            await unwrap(
                api.PUT('/admin/users/{userId}/roles', {
                    params: { path: { userId: user.id } },
                    body: { roles },
                }),
            );
            await onSaved();
        } catch (e) {
            if (isIdpUnavailable(e)) {
                onIdpDown(e);
                onClose();
                return;
            }
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            title={`Roles for ${name}`}
            okText="Save roles"
            confirmLoading={saving}
            onOk={() => form.submit()}
            onCancel={onClose}
            destroyOnHidden
        >
            <Typography.Paragraph>
                One base role, plus Administrator when needed. Other identity-provider roles are
                left as they are.
            </Typography.Paragraph>
            {error ? <ProblemAlert error={error} /> : null}
            <Form<RoleValues>
                form={form}
                layout="vertical"
                onFinish={(values) => void onFinish(values)}
                initialValues={{ role: user.role ?? 'employee', is_admin: user.is_admin }}
            >
                <Form.Item
                    name="role"
                    label="Role"
                    rules={[{ required: true, message: 'Choose a role' }]}
                >
                    <Select
                        options={BASE_ROLES.map((role) => ({
                            value: role,
                            label: ROLE_LABEL[role],
                        }))}
                    />
                </Form.Item>
                <Form.Item name="is_admin" label="Administrator" valuePropName="checked">
                    <Switch />
                </Form.Item>
            </Form>
        </Modal>
    );
}
