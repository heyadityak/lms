'use client';

import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    App,
    Button,
    DatePicker,
    Descriptions,
    Form,
    Popconfirm,
    Select,
    Space,
    Typography,
} from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { ApiError, browserApi as api, unwrap, unwrapEmpty, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useShell } from './providers';
import { PageTitle, ProblemAlert, ROLE_LABEL, fmtDate } from './ui';

type Delegation = Schemas['ApprovalDelegation'];

interface Values {
    delegate_id?: string;
    start_date?: Dayjs | null;
    end_date?: Dayjs | null;
}

/** The caller's single approval delegation (`/me/approval-delegations`). */
export function DelegationView() {
    const { user } = useShell();
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const [form] = Form.useForm<Values>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [actionError, setActionError] = useState<unknown>(null);

    const current = useQuery({
        queryKey: ['approval-delegations'],
        queryFn: async (): Promise<Delegation | null> => {
            try {
                return (await unwrap(api.GET('/me/approval-delegations'))).data;
            } catch (e) {
                if (e instanceof ApiError && e.status === 404) return null;
                throw e;
            }
        },
    });

    const remove = async (row: Delegation) => {
        setActionError(null);
        try {
            await unwrapEmpty(api.DELETE('/me/approval-delegations'));
            message.success(`Removed delegation to ${row.delegate.name}`);
            await queryClient.invalidateQueries({ queryKey: ['approval-delegations'] });
        } catch (e) {
            setActionError(e);
        }
    };

    const onFinish = async (values: Values) => {
        if (!values.delegate_id || !values.start_date || !values.end_date) return;
        const body: Schemas['ApprovalDelegationWrite'] = {
            delegate_id: values.delegate_id,
            start_date: values.start_date.format('YYYY-MM-DD'),
            end_date: values.end_date.format('YYYY-MM-DD'),
        };
        setSaving(true);
        setError(null);
        try {
            await unwrap(api.POST('/me/approval-delegations', { body }));
            message.success('Delegation saved');
            form.resetFields();
            await queryClient.invalidateQueries({ queryKey: ['approval-delegations'] });
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <PageTitle>Approval delegation</PageTitle>
            <Typography.Paragraph>
                The delegate approves in your place from the start date through the end date. One
                delegation at a time.
            </Typography.Paragraph>
            {current.isError && (
                <ProblemAlert error={current.error} onRetry={() => current.refetch()} />
            )}
            {actionError ? <ProblemAlert error={actionError} /> : null}
            {current.data && (
                <>
                    <Descriptions
                        column={1}
                        size="small"
                        style={{ marginBottom: 16, maxWidth: 480 }}
                        items={[
                            {
                                key: 'delegate',
                                label: 'Delegate',
                                children: current.data.delegate.name,
                            },
                            {
                                key: 'start',
                                label: 'Start',
                                children: fmtDate(current.data.start_date),
                            },
                            { key: 'end', label: 'End', children: fmtDate(current.data.end_date) },
                        ]}
                    />
                    <Popconfirm
                        title={`Remove delegation to ${current.data.delegate.name}?`}
                        okText="Remove delegation"
                        okButtonProps={{ danger: true }}
                        cancelText="Back"
                        onConfirm={() => remove(current.data!)}
                    >
                        <Button
                            danger
                            aria-label={`Remove delegation to ${current.data.delegate.name}`}
                        >
                            Delete
                        </Button>
                    </Popconfirm>
                </>
            )}
            {current.data === null && (
                <>
                    {error ? <ProblemAlert error={error} /> : null}
                    <Form<Values> form={form} layout="vertical" onFinish={onFinish}>
                        <Space wrap align="start">
                            <Form.Item
                                name="delegate_id"
                                label="Delegate"
                                rules={[{ required: true, message: 'Choose a delegate' }]}
                                style={{ minWidth: 240 }}
                            >
                                <DelegateSelect selfId={user.id} />
                            </Form.Item>
                            <Form.Item
                                name="start_date"
                                label="Start"
                                extra="Format YYYY-MM-DD"
                                rules={[{ required: true, message: 'Start date is required' }]}
                            >
                                <DatePicker format="YYYY-MM-DD" />
                            </Form.Item>
                            <Form.Item
                                name="end_date"
                                label="End"
                                extra="Format YYYY-MM-DD"
                                dependencies={['start_date']}
                                rules={[
                                    { required: true, message: 'End date is required' },
                                    ({ getFieldValue }) => ({
                                        validator: async (_, value: Dayjs | null) => {
                                            const start = getFieldValue(
                                                'start_date',
                                            ) as Dayjs | null;
                                            if (value && start && value.isBefore(start, 'day')) {
                                                throw new Error(
                                                    'End date is before the start date',
                                                );
                                            }
                                        },
                                    }),
                                ]}
                            >
                                <DatePicker format="YYYY-MM-DD" />
                            </Form.Item>
                            <Button
                                type="primary"
                                htmlType="submit"
                                loading={saving}
                                style={{ marginTop: 30 }}
                            >
                                Save
                            </Button>
                        </Space>
                    </Form>
                </>
            )}
        </>
    );
}

function DelegateSelect({ selfId }: { selfId: string }) {
    const [q, setQ] = useState('');
    const list = useQuery({
        queryKey: ['users', 'delegates', q],
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
        .filter((row) => row.id !== selfId && (row.role === 'manager' || row.role === 'hr_admin'))
        .map((row) => ({ value: row.id, label: `${row.name} (${ROLE_LABEL[row.role]})` }));
    return (
        <Select
            showSearch
            filterOption={false}
            onSearch={setQ}
            options={options}
            loading={list.isFetching}
            placeholder="Search by name"
            aria-label="Delegate"
        />
    );
}
