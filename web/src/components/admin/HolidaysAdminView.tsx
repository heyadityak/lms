'use client';

import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, DatePicker, Empty, Form, Input, Modal, Popconfirm, Space, Table } from 'antd';
import type { TableColumnsType } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { browserApi as api, unwrap, unwrapEmpty, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useHolidays } from '@/lib/queries';
import { PageTitle, ProblemAlert, fmtDate } from '../ui';

type Holiday = Schemas['Holiday'];

const MIN_YEAR = 2000;
const MAX_YEAR = 2100;
const clampYear = (year: number) => Math.min(MAX_YEAR, Math.max(MIN_YEAR, year));

interface Values {
    date: Dayjs | null;
    name: string;
}

/** Holiday calendar admin (UC-H4). The page 404s when the holidays module is off. */
export function HolidaysAdminView() {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const [year, setYear] = useState(() => clampYear(dayjs().year()));
    const list = useHolidays(true, year);
    const [editor, setEditor] = useState<Holiday | 'new' | null>(null);
    const [actionError, setActionError] = useState<unknown>(null);

    const remove = async (row: Holiday) => {
        setActionError(null);
        try {
            await unwrapEmpty(
                api.DELETE('/holidays/{holidayId}', { params: { path: { holidayId: row.id } } }),
            );
            message.success(`Deleted ${row.name}`);
            await queryClient.invalidateQueries({ queryKey: ['holidays'] });
        } catch (e) {
            setActionError(e);
        }
    };

    const columns: TableColumnsType<Holiday> = [
        { title: 'Date', key: 'date', dataIndex: 'date', render: fmtDate },
        { title: 'Name', key: 'name', dataIndex: 'name' },
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
                        okText="Delete holiday"
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
            <PageTitle>Holiday calendar</PageTitle>
            <Space style={{ marginBottom: 16 }} align="center">
                <span>Leave year</span>
                <Button
                    icon={<LeftOutlined />}
                    aria-label="Previous leave year"
                    disabled={year <= MIN_YEAR}
                    onClick={() => setYear((current) => clampYear(current - 1))}
                />
                <span
                    aria-live="polite"
                    style={{ minWidth: 48, textAlign: 'center', fontWeight: 600 }}
                >
                    {year}
                </span>
                <Button
                    icon={<RightOutlined />}
                    aria-label="Next leave year"
                    disabled={year >= MAX_YEAR}
                    onClick={() => setYear((current) => clampYear(current + 1))}
                />
                <Button type="primary" onClick={() => setEditor('new')}>
                    Add holiday
                </Button>
            </Space>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {actionError ? <ProblemAlert error={actionError} /> : null}
            <Table<Holiday>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data}
                loading={list.isFetching}
                pagination={false}
                locale={{ emptyText: <Empty description={`No holidays in ${year}`} /> }}
            />
            {editor && (
                <HolidayEditor
                    target={editor}
                    year={year}
                    onClose={() => setEditor(null)}
                    onSaved={async (date) => {
                        message.success('Holiday saved');
                        setEditor(null);
                        setYear(clampYear(dayjs(date).year()));
                        await queryClient.invalidateQueries({ queryKey: ['holidays'] });
                    }}
                />
            )}
        </>
    );
}

function HolidayEditor({
    target,
    year,
    onClose,
    onSaved,
}: {
    target: Holiday | 'new';
    year: number;
    onClose: () => void;
    onSaved: (date: string) => Promise<void>;
}) {
    const [form] = Form.useForm<Values>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const editing = target === 'new' ? null : target;

    const onFinish = async (values: Values) => {
        if (!values.date) return;
        const body: Schemas['HolidayWrite'] = {
            date: values.date.format('YYYY-MM-DD'),
            name: values.name.trim(),
        };
        setSaving(true);
        setError(null);
        try {
            if (editing) {
                await unwrap(
                    api.PATCH('/holidays/{holidayId}', {
                        params: { path: { holidayId: editing.id } },
                        body,
                    }),
                );
            } else {
                await unwrap(api.POST('/holidays', { body }));
            }
            await onSaved(body.date);
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            title={editing ? `Edit ${editing.name}` : 'Add holiday'}
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
                        ? { date: dayjs(editing.date), name: editing.name }
                        : { date: dayjs(`${year}-01-01`), name: '' }
                }
            >
                <Form.Item
                    name="date"
                    label="Date"
                    extra="Format YYYY-MM-DD"
                    rules={[{ required: true, message: 'Date is required' }]}
                >
                    <DatePicker format="YYYY-MM-DD" style={{ width: '100%' }} />
                </Form.Item>
                <Form.Item
                    name="name"
                    label="Name"
                    rules={[{ required: true, whitespace: true, message: 'Name is required' }]}
                >
                    <Input maxLength={100} />
                </Form.Item>
            </Form>
        </Modal>
    );
}
