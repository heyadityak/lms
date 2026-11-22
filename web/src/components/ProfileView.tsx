'use client';

import { UploadOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    App,
    Avatar,
    Button,
    DatePicker,
    Descriptions,
    Form,
    Grid,
    Input,
    Select,
    Skeleton,
    Space,
    Typography,
    type SelectProps,
} from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useRef, useState } from 'react';
import { browserApi as api, unwrap, unwrapEmpty, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { ActiveTag, fmtDate, InitialsAvatar, PageTitle, ProblemAlert, ROLE_LABEL } from './ui';

type User = Schemas['User'];

interface ProfileValues {
    department_id?: string | null;
    location_id?: string | null;
    manager_id?: string | null;
    date_of_joining?: Dayjs | null;
    employee_code?: string;
    designation?: string;
    employment_type?: string;
}

const PHOTO_BYTES = 1_048_576;

function blank(value: string | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}

function show(value: string | null | undefined): string {
    const trimmed = value?.trim();
    return trimmed ? trimmed : '—';
}

function photoError(file: File): string | null {
    const ext = file.name.split('.').pop()?.toLowerCase();
    const typeOk =
        file.type === 'image/png' ||
        file.type === 'image/jpeg' ||
        file.type === 'image/webp' ||
        ext === 'png' ||
        ext === 'jpg' ||
        ext === 'jpeg' ||
        ext === 'webp';
    if (!typeOk) return 'Upload a PNG, JPEG, or WebP image';
    if (file.size > PHOTO_BYTES) return 'Photo must be 1 MB or smaller';
    return null;
}

/** Current user from `GET /me`. HR admins can edit the LMS fields on `PATCH /users/{id}`. */
export function ProfileView() {
    const me = useQuery({
        queryKey: ['me'],
        queryFn: () => unwrap(api.GET('/me')),
    });

    return (
        <>
            <PageTitle>Profile</PageTitle>
            {me.isPending && <Skeleton active avatar paragraph={{ rows: 6 }} />}
            {me.isError && <ProblemAlert error={me.error} onRetry={() => me.refetch()} />}
            {me.data &&
                (me.data.data.role === 'hr_admin' ? (
                    <ProfileEditor user={me.data.data} />
                ) : (
                    <ProfileReadOnly user={me.data.data} />
                ))}
        </>
    );
}

function ProfileReadOnly({ user }: { user: User }) {
    const screens = Grid.useBreakpoint();
    const column = screens.md === false ? 1 : 2;
    return (
        <>
            <ProfileIdentity user={user} />
            <Descriptions
                column={column}
                style={{ marginTop: 24 }}
                items={[
                    {
                        key: 'employee_code',
                        label: 'Employee code',
                        children: show(user.employee_code),
                    },
                    { key: 'designation', label: 'Designation', children: show(user.designation) },
                    {
                        key: 'employment_type',
                        label: 'Employment type',
                        children: show(user.employment_type),
                    },
                    {
                        key: 'department',
                        label: 'Department',
                        children: show(user.department?.name),
                    },
                    { key: 'location', label: 'Location', children: show(user.location?.name) },
                    { key: 'manager', label: 'Manager', children: show(user.manager?.name) },
                    {
                        key: 'date_of_joining',
                        label: 'Date of joining',
                        children: user.date_of_joining ? fmtDate(user.date_of_joining) : '—',
                    },
                ]}
            />
        </>
    );
}

function ProfileEditor({ user }: { user: User }) {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const [form] = Form.useForm<ProfileValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const departments = useQuery({
        queryKey: ['departments'],
        queryFn: async () => (await unwrap(api.GET('/departments'))).data,
        staleTime: 60_000,
    });
    const locations = useQuery({
        queryKey: ['locations'],
        queryFn: async () => (await unwrap(api.GET('/locations'))).data,
        staleTime: 60_000,
    });

    const onFinish = async (values: ProfileValues) => {
        // Name, email and role come from Keycloak. Sending `role` is 422 ROLE_READ_ONLY.
        const body: Schemas['UserUpdate'] = {
            department_id: values.department_id ?? null,
            location_id: values.location_id ?? null,
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
            const saved = await unwrap(
                api.PATCH('/users/{userId}', { params: { path: { userId: user.id } }, body }),
            );
            queryClient.setQueryData(['me'], saved);
            message.success('Profile saved');
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    const departmentOptions = (departments.data ?? []).map((row) => ({
        value: row.id,
        label: row.name,
    }));
    if (
        user.department &&
        !departmentOptions.some((option) => option.value === user.department?.id)
    ) {
        departmentOptions.unshift({ value: user.department.id, label: user.department.name });
    }
    const locationOptions = (locations.data ?? []).map((row) => ({
        value: row.id,
        label: row.name,
    }));
    if (user.location && !locationOptions.some((option) => option.value === user.location?.id)) {
        locationOptions.unshift({ value: user.location.id, label: user.location.name });
    }

    return (
        <>
            <ProfileIdentity user={user} />
            <Typography.Paragraph type="secondary">
                Name and email are managed in your organization account.
            </Typography.Paragraph>
            {departments.isError && <ProblemAlert error={departments.error} />}
            {locations.isError && <ProblemAlert error={locations.error} />}
            {error ? <ProblemAlert error={error} /> : null}
            <Form<ProfileValues>
                form={form}
                layout="vertical"
                style={{ maxWidth: 560 }}
                onFinish={onFinish}
                initialValues={{
                    department_id: user.department?.id ?? null,
                    location_id: user.location?.id ?? null,
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
                        options={departmentOptions}
                    />
                </Form.Item>
                <Form.Item name="location_id" label="Location">
                    <Select
                        allowClear
                        placeholder="No location"
                        loading={locations.isFetching}
                        options={locationOptions}
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
                <Button type="primary" htmlType="submit" loading={saving}>
                    Save profile
                </Button>
            </Form>
        </>
    );
}

function ProfileIdentity({ user }: { user: User }) {
    return (
        <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
            <ProfilePhoto user={user} />
            <div>
                <Typography.Title level={2} style={{ margin: 0, fontSize: 20 }}>
                    {user.name}
                </Typography.Title>
                <Typography.Paragraph style={{ margin: '4px 0 8px' }}>
                    {user.email}
                </Typography.Paragraph>
                <Space wrap>
                    <Typography.Text>{ROLE_LABEL[user.role]}</Typography.Text>
                    <ActiveTag active={user.is_active} />
                </Space>
            </div>
        </div>
    );
}

function ProfilePhoto({ user }: { user: User }) {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const input = useRef<HTMLInputElement>(null);
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const src =
        user.avatar_url && failedUrl !== user.avatar_url
            ? `/bff/api/users/${user.id}/avatar`
            : null;

    const upload = async (file: File) => {
        const invalid = photoError(file);
        if (invalid) {
            message.error(invalid);
            return;
        }
        const body = new FormData();
        body.set('file', file);
        setBusy(true);
        setError(null);
        try {
            await unwrapEmpty(
                api.POST('/me/avatar', {
                    // Runtime body is FormData; openapi-fetch leaves that intact (not JSON).
                    body: body as unknown as { file: string },
                }),
            );
            await queryClient.invalidateQueries({ queryKey: ['me'] });
            message.success('Photo updated');
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        setBusy(true);
        setError(null);
        try {
            await unwrapEmpty(api.DELETE('/me/avatar'));
            await queryClient.invalidateQueries({ queryKey: ['me'] });
            message.success('Photo removed');
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div>
            {src ? (
                <Avatar
                    src={src}
                    alt=""
                    size={72}
                    onError={() => {
                        setFailedUrl(user.avatar_url);
                        return true;
                    }}
                />
            ) : (
                <InitialsAvatar name={user.name} size={72} />
            )}
            <div style={{ marginTop: 8 }}>
                <Button
                    icon={<UploadOutlined aria-hidden />}
                    loading={busy}
                    onClick={() => input.current?.click()}
                >
                    Upload photo
                </Button>
                {user.avatar_url && (
                    <Button type="link" disabled={busy} onClick={() => void remove()}>
                        Remove photo
                    </Button>
                )}
                <input
                    ref={input}
                    type="file"
                    accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp"
                    aria-label="Choose photo"
                    hidden
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        event.target.value = '';
                        if (file) void upload(file);
                    }}
                />
            </div>
            {error ? (
                <div style={{ marginTop: 8 }}>
                    <ProblemAlert error={error} />
                </div>
            ) : null}
        </div>
    );
}

function ManagerSelect({ user, ...field }: { user: User } & SelectProps) {
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
            {...field}
            aria-label="Manager"
        />
    );
}
