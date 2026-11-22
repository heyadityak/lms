'use client';

import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Empty, Form, Input, Modal, Popconfirm, Space, Table } from 'antd';
import type { TableColumnsType } from 'antd';
import { useState } from 'react';
import { browserApi as api, unwrap, unwrapEmpty, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useDepartments } from '@/lib/queries';
import { PageTitle, ProblemAlert } from '../ui';

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

function DepartmentEditor({
    target,
    onClose,
    onSaved,
}: {
    target: Department | 'new';
    onClose: () => void;
    onSaved: (name: string) => Promise<void>;
}) {
    const [form] = Form.useForm<Schemas['DepartmentWrite']>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const editing = target === 'new' ? null : target;

    const onFinish = async (values: Schemas['DepartmentWrite']) => {
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
        <Modal
            open
            title={editing ? `Edit ${editing.name}` : 'Add department'}
            okText="Save"
            confirmLoading={saving}
            onOk={() => form.submit()}
            onCancel={onClose}
            destroyOnHidden
        >
            {error ? <ProblemAlert error={error} /> : null}
            <Form<Schemas['DepartmentWrite']>
                form={form}
                layout="vertical"
                initialValues={
                    editing ? { code: editing.code, name: editing.name } : { code: '', name: '' }
                }
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
            </Form>
        </Modal>
    );
}
