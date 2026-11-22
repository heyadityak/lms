'use client';

import { useQueryClient } from '@tanstack/react-query';
import {
    App,
    Button,
    Form,
    Input,
    InputNumber,
    Modal,
    Radio,
    Space,
    Switch,
    Table,
    Typography,
} from 'antd';
import type { TableColumnsType } from 'antd';
import { useState } from 'react';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useAllLeaveTypes } from '@/lib/queries';
import { ActiveTag, PageTitle, ProblemAlert } from '../ui';

type LeaveType = Schemas['LeaveType'];

interface Values {
    code?: string;
    name: string;
    annual_quota: number | null;
    tracks_balance: boolean;
    carry_forward_cap?: number | null;
    max_balance?: number | null;
    allow_half_day: boolean;
    max_backdate_days: number | null;
    sandwich: boolean;
    accrual_frequency: Schemas['AccrualFrequency'];
    is_active?: boolean;
}

function daysError(
    value: number | null | undefined,
    label: string,
    required: boolean,
): string | null {
    if (value == null) return required ? `${label} is required` : null;
    if (value < 0 || Math.abs(value * 2 - Math.round(value * 2)) > 1e-9) {
        return `${label} must be zero or a multiple of 0.5`;
    }
    return null;
}

/** Leave types (UC-H3). Code is set on create and never sent on update. No delete. */
export function LeaveTypesView() {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const list = useAllLeaveTypes();
    const [editor, setEditor] = useState<LeaveType | 'new' | null>(null);

    const columns: TableColumnsType<LeaveType> = [
        { title: 'Code', dataIndex: 'code', key: 'code' },
        { title: 'Name', dataIndex: 'name', key: 'name' },
        { title: 'Quota', dataIndex: 'annual_quota', key: 'annual_quota', align: 'right' },
        {
            title: 'Tracks balance',
            key: 'tracks_balance',
            render: (_, row) => (row.tracks_balance ? 'Yes' : 'No'),
        },
        {
            title: 'Carry forward cap',
            key: 'carry_forward_cap',
            render: (_, row) => (row.carry_forward_cap == null ? 'All' : row.carry_forward_cap),
        },
        {
            title: 'Max balance',
            key: 'max_balance',
            render: (_, row) => (row.max_balance == null ? 'None' : row.max_balance),
        },
        {
            title: 'Half day',
            key: 'allow_half_day',
            render: (_, row) => (row.allow_half_day ? 'Yes' : 'No'),
        },
        {
            title: 'Sandwich',
            key: 'sandwich',
            render: (_, row) => (row.sandwich ? 'On' : 'Off'),
        },
        {
            title: 'Accrual',
            key: 'accrual_frequency',
            render: (_, row) => (row.accrual_frequency === 'monthly' ? 'Monthly' : 'Yearly'),
        },
        { title: 'Backdate days', dataIndex: 'max_backdate_days', key: 'max_backdate_days' },
        {
            title: 'Status',
            key: 'status',
            render: (_, row) => <ActiveTag active={row.is_active} />,
        },
        {
            title: 'Action',
            key: 'action',
            render: (_, row) => (
                <Button type="link" onClick={() => setEditor(row)} aria-label={`Edit ${row.name}`}>
                    Edit
                </Button>
            ),
        },
    ];

    return (
        <>
            <PageTitle>Leave types</PageTitle>
            <Space style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
                <Button type="primary" onClick={() => setEditor('new')}>
                    Add leave type
                </Button>
            </Space>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            <Table<LeaveType>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data}
                loading={list.isFetching}
                pagination={false}
                scroll={{ x: 1100 }}
            />
            {editor && (
                <LeaveTypeEditor
                    target={editor}
                    onClose={() => setEditor(null)}
                    onSaved={async (name) => {
                        message.success(`Saved ${name}`);
                        setEditor(null);
                        await queryClient.invalidateQueries({ queryKey: ['leave-types'] });
                    }}
                />
            )}
        </>
    );
}

function shared(values: Values) {
    return {
        name: values.name.trim(),
        annual_quota: values.annual_quota ?? 0,
        tracks_balance: values.tracks_balance,
        carry_forward_cap: values.carry_forward_cap ?? null,
        max_balance: values.max_balance ?? null,
        allow_half_day: values.allow_half_day,
        max_backdate_days: values.max_backdate_days ?? 0,
        sandwich: values.sandwich,
        accrual_frequency: values.accrual_frequency,
    };
}

function LeaveTypeEditor({
    target,
    onClose,
    onSaved,
}: {
    target: LeaveType | 'new';
    onClose: () => void;
    onSaved: (name: string) => Promise<void>;
}) {
    const [form] = Form.useForm<Values>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const editing = target === 'new' ? null : target;

    const onFinish = async (values: Values) => {
        setSaving(true);
        setError(null);
        try {
            if (editing) {
                const body: Schemas['LeaveTypeUpdate'] = {
                    ...shared(values),
                    is_active: values.is_active ?? editing.is_active,
                };
                await unwrap(
                    api.PATCH('/leave-types/{leaveTypeId}', {
                        params: { path: { leaveTypeId: editing.id } },
                        body,
                    }),
                );
            } else {
                const body: Schemas['LeaveTypeCreate'] = {
                    ...shared(values),
                    code: (values.code ?? '').trim().toUpperCase(),
                };
                await unwrap(api.POST('/leave-types', { body }));
            }
            await onSaved(values.name.trim());
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            title={editing ? `Edit ${editing.name}` : 'Add leave type'}
            okText="Save"
            confirmLoading={saving}
            onOk={() => form.submit()}
            onCancel={onClose}
            destroyOnHidden
        >
            {error ? <ProblemAlert error={error} /> : null}
            <Form<Values>
                form={form}
                layout="vertical"
                onFinish={onFinish}
                initialValues={
                    editing
                        ? {
                              name: editing.name,
                              annual_quota: editing.annual_quota,
                              tracks_balance: editing.tracks_balance,
                              carry_forward_cap: editing.carry_forward_cap,
                              max_balance: editing.max_balance,
                              allow_half_day: editing.allow_half_day,
                              max_backdate_days: editing.max_backdate_days,
                              sandwich: editing.sandwich,
                              accrual_frequency: editing.accrual_frequency ?? 'yearly',
                              is_active: editing.is_active,
                          }
                        : {
                              tracks_balance: true,
                              allow_half_day: true,
                              max_backdate_days: 0,
                              annual_quota: null,
                              sandwich: false,
                              accrual_frequency: 'yearly',
                          }
                }
            >
                {editing ? (
                    <div style={{ marginBottom: 24 }}>
                        <label
                            htmlFor="leave-type-code"
                            style={{ display: 'block', marginBottom: 8 }}
                        >
                            Code
                        </label>
                        <Input id="leave-type-code" value={editing.code} disabled />
                        <Typography.Text type="secondary">Code cannot be changed.</Typography.Text>
                    </div>
                ) : (
                    <Form.Item
                        name="code"
                        label="Code"
                        normalize={(value: string) => value?.toUpperCase()}
                        rules={[
                            { required: true, whitespace: true, message: 'Code is required' },
                            {
                                pattern: /^[A-Z0-9_]{1,10}$/,
                                message: 'Use 1–10 characters: A–Z, 0–9 or _',
                            },
                        ]}
                    >
                        <Input maxLength={10} />
                    </Form.Item>
                )}
                <Form.Item
                    name="name"
                    label="Name"
                    rules={[{ required: true, whitespace: true, message: 'Name is required' }]}
                >
                    <Input maxLength={100} />
                </Form.Item>
                <Form.Item
                    name="annual_quota"
                    label="Annual quota"
                    rules={[
                        {
                            validator: async (_, value: number | null) => {
                                const invalid = daysError(value, 'Annual quota', true);
                                if (invalid) throw new Error(invalid);
                            },
                        },
                    ]}
                >
                    <InputNumber min={0} step={0.5} style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item name="tracks_balance" label="Tracks balance" valuePropName="checked">
                    <Switch checkedChildren="Yes" unCheckedChildren="No" />
                </Form.Item>
                <Form.Item
                    name="carry_forward_cap"
                    label="Carry forward cap"
                    extra="Empty means the full balance carries. 0 means nothing carries."
                    rules={[
                        {
                            validator: async (_, value: number | null) => {
                                const invalid = daysError(value, 'Carry forward cap', false);
                                if (invalid) throw new Error(invalid);
                            },
                        },
                    ]}
                >
                    <InputNumber min={0} step={0.5} style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item
                    name="max_balance"
                    label="Max balance"
                    extra="Empty means no ceiling."
                    rules={[
                        {
                            validator: async (_, value: number | null) => {
                                const invalid = daysError(value, 'Max balance', false);
                                if (invalid) throw new Error(invalid);
                            },
                        },
                    ]}
                >
                    <InputNumber min={0} step={0.5} style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item name="allow_half_day" label="Half day allowed" valuePropName="checked">
                    <Switch checkedChildren="Yes" unCheckedChildren="No" />
                </Form.Item>
                <Form.Item
                    name="sandwich"
                    label="Sandwich rule"
                    valuePropName="checked"
                    extra="Count weekends and holidays that fall between the leave days."
                >
                    <Switch checkedChildren="On" unCheckedChildren="Off" />
                </Form.Item>
                <Form.Item
                    name="accrual_frequency"
                    label="Accrual frequency"
                    rules={[{ required: true, message: 'Choose yearly or monthly' }]}
                >
                    <Radio.Group
                        options={[
                            { value: 'yearly', label: 'Yearly' },
                            { value: 'monthly', label: 'Monthly' },
                        ]}
                    />
                </Form.Item>
                <Form.Item
                    name="max_backdate_days"
                    label="Max backdate days"
                    rules={[{ required: true, message: 'Backdate days are required' }]}
                >
                    <InputNumber min={0} precision={0} style={{ width: '100%' }} />
                </Form.Item>
                {editing && (
                    <Form.Item name="is_active" label="Active" valuePropName="checked">
                        <Switch checkedChildren="Yes" unCheckedChildren="No" />
                    </Form.Item>
                )}
            </Form>
        </Modal>
    );
}
