'use client';

import { useQueryClient } from '@tanstack/react-query';
import {
    App,
    Button,
    Drawer,
    Empty,
    Form,
    Input,
    InputNumber,
    Popconfirm,
    Space,
    Spin,
    Table,
    Typography,
} from 'antd';
import type { TableColumnsType } from 'antd';
import { useState } from 'react';
import {
    ApiError,
    browserApi as api,
    unwrap,
    unwrapEmpty,
    type Problem,
    type Schemas,
} from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useAllLeaveTypes, useDepartments } from '@/lib/queries';
import { PageTitle, ProblemAlert } from '../ui';

type Quota = Schemas['DepartmentLeaveQuota'];

type DeptValues = Schemas['DepartmentWrite'] & {
    quotas?: Record<string, number | null | undefined>;
};

type Department = Schemas['Department'];

/** Departments (UC-H1). Delete of a non-empty department shows the API error. */
export function DepartmentsView() {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const list = useDepartments();
    const [editor, setEditor] = useState<Department | 'new' | null>(null);
    const [actionError, setActionError] = useState<unknown>(null);

    const remove = async (row: Department) => {
        setActionError(null);
        try {
            await unwrapEmpty(
                api.DELETE('/departments/{departmentId}', {
                    params: { path: { departmentId: row.id } },
                }),
            );
            message.success(`Deleted ${row.name}`);
            await queryClient.invalidateQueries({ queryKey: ['departments'] });
        } catch (e) {
            setActionError(e);
        }
    };

    const columns: TableColumnsType<Department> = [
        { title: 'Code', dataIndex: 'code', key: 'code' },
        { title: 'Name', dataIndex: 'name', key: 'name' },
        { title: 'People', dataIndex: 'users_count', key: 'users_count', align: 'right' },
        {
            title: 'Action',
            key: 'action',
            render: (_, row) => (
                <Space>
                    <Button
                        type="link"
                        onClick={() => setEditor(row)}
                        aria-label={`Edit ${row.name}`}
                    >
                        Edit
                    </Button>
                    <Popconfirm
                        title={`Delete ${row.name}?`}
                        description="Only an empty department can be deleted."
                        okText="Delete department"
                        okButtonProps={{ danger: true }}
                        cancelText="Back"
                        onConfirm={() => remove(row)}
                    >
                        <Button type="link" danger aria-label={`Delete ${row.name}`}>
                            Delete
                        </Button>
                    </Popconfirm>
                </Space>
            ),
        },
    ];

    return (
        <>
            <PageTitle>Departments</PageTitle>
            <Space style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
                <Button type="primary" onClick={() => setEditor('new')}>
                    Add department
                </Button>
            </Space>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {actionError ? <ProblemAlert error={actionError} /> : null}
            <Table<Department>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data}
                loading={list.isFetching}
                pagination={false}
                scroll={{ x: 640 }}
                locale={{ emptyText: <Empty description="No departments yet" /> }}
            />
            {editor && (
                <DepartmentEditor
                    target={editor}
                    onClose={() => setEditor(null)}
                    onSaved={async (name) => {
                        message.success(`Saved ${name}`);
                        setEditor(null);
                        await queryClient.invalidateQueries({ queryKey: ['departments'] });
                    }}
                />
            )}
        </>
    );
}

function quotaMap(rows: Quota[] | undefined): Record<string, number | null> {
    const values: Record<string, number | null> = {};
    for (const row of rows ?? []) values[row.leave_type_id] = row.annual_quota;
    return values;
}

async function putQuota(departmentId: string, body: Schemas['DepartmentLeaveQuotaWrite']) {
    const { error, response } = await api.PUT('/departments/{departmentId}/leave-quotas', {
        params: { path: { departmentId } },
        body,
    });
    if (!response.ok) throw new ApiError(response.status, (error ?? {}) as Problem);
}

function daysError(value: number | null | undefined): string | null {
    if (value == null) return null;
    if (value < 0 || Math.abs(value * 2 - Math.round(value * 2)) > 1e-9) {
        return 'Quota must be zero or a multiple of 0.5';
    }
    return null;
}

function DepartmentEditor({
    target,
    onClose,
    onSaved,
}: {
    target: Department | 'new';
    onClose: () => void;
    onSaved: (name: string) => Promise<void>;
}) {
    const [form] = Form.useForm<DeptValues>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const editing = target === 'new' ? null : target;
    const types = useAllLeaveTypes();
    const loading = editing !== null && types.isPending;
    const canSaveQuotas = editing !== null && types.isSuccess;

    const onFinish = async (values: DeptValues) => {
        const body: Schemas['DepartmentWrite'] = {
            code: values.code.trim(),
            name: values.name.trim(),
        };
        setSaving(true);
        setError(null);
        try {
            if (editing) {
                await unwrap(
                    api.PATCH('/departments/{departmentId}', {
                        params: { path: { departmentId: editing.id } },
                        body,
                    }),
                );
                if (canSaveQuotas) {
                    const previous = new Map(
                        (editing.leave_quotas ?? []).map((row) => [
                            row.leave_type_id,
                            row.annual_quota,
                        ]),
                    );
                    for (const type of types.data ?? []) {
                        const next = values.quotas?.[type.id] ?? null;
                        const prev = previous.get(type.id) ?? null;
                        if (next === prev) continue;
                        await putQuota(editing.id, {
                            leave_type_id: type.id,
                            annual_quota: next,
                        });
                    }
                }
            } else {
                await unwrap(api.POST('/departments', { body }));
            }
            await onSaved(body.name);
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Drawer
            open
            size={520}
            title={editing ? `Edit ${editing.name}` : 'Add department'}
            onClose={onClose}
            extra={
                <Button type="primary" loading={saving} onClick={() => form.submit()}>
                    Save
                </Button>
            }
            destroyOnHidden
        >
            {error ? <ProblemAlert error={error} /> : null}
            {loading ? (
                <Spin />
            ) : (
                <Form<DeptValues>
                    form={form}
                    layout="vertical"
                    initialValues={{
                        code: editing?.code ?? '',
                        name: editing?.name ?? '',
                        quotas: quotaMap(editing?.leave_quotas),
                    }}
                    onFinish={onFinish}
                >
                    <Form.Item
                        name="code"
                        label="Code"
                        rules={[{ required: true, whitespace: true, message: 'Code is required' }]}
                    >
                        <Input maxLength={20} />
                    </Form.Item>
                    <Form.Item
                        name="name"
                        label="Name"
                        rules={[{ required: true, whitespace: true, message: 'Name is required' }]}
                    >
                        <Input maxLength={100} />
                    </Form.Item>
                    {canSaveQuotas && (
                        <>
                            <Typography.Title level={2} style={{ fontSize: 16 }}>
                                Quota overrides
                            </Typography.Title>
                            <Typography.Paragraph type="secondary">
                                Empty uses the leave type&apos;s annual quota.
                            </Typography.Paragraph>
                            {(types.data ?? []).map((type) => (
                                <Form.Item
                                    key={type.id}
                                    name={['quotas', type.id]}
                                    label={`${type.name} (${type.code})`}
                                    extra={`Type quota ${type.annual_quota}`}
                                    rules={[
                                        {
                                            validator: async (_, value: number | null) => {
                                                const invalid = daysError(value);
                                                if (invalid) throw new Error(invalid);
                                            },
                                        },
                                    ]}
                                >
                                    <InputNumber min={0} step={0.5} style={{ width: '100%' }} />
                                </Form.Item>
                            ))}
                        </>
                    )}
                    {editing && types.isError ? <ProblemAlert error={types.error} /> : null}
                </Form>
            )}
        </Drawer>
    );
}
