'use client';

import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Empty, Form, Input, Modal, Popconfirm, Space, Table } from 'antd';
import type { TableColumnsType } from 'antd';
import { useState } from 'react';
import { browserApi as api, unwrap, unwrapEmpty, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useLocations } from '@/lib/queries';
import { PageTitle, ProblemAlert } from '../ui';

type Location = Schemas['Location'];

/** Offices the holiday calendar and user records can point at. */
export function LocationsView() {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const list = useLocations();
    const [editor, setEditor] = useState<Location | 'new' | null>(null);
    const [actionError, setActionError] = useState<unknown>(null);

    const remove = async (row: Location) => {
        setActionError(null);
        try {
            await unwrapEmpty(
                api.DELETE('/locations/{locationId}', {
                    params: { path: { locationId: row.id } },
                }),
            );
            message.success(`Deleted ${row.name}`);
            await queryClient.invalidateQueries({ queryKey: ['locations'] });
        } catch (e) {
            setActionError(e);
        }
    };

    const columns: TableColumnsType<Location> = [
        { title: 'Code', dataIndex: 'code', key: 'code' },
        { title: 'Name', dataIndex: 'name', key: 'name' },
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
                        description="A location with people or holidays cannot be deleted."
                        okText="Delete location"
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
            <PageTitle>Locations</PageTitle>
            <Space style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
                <Button type="primary" onClick={() => setEditor('new')}>
                    Add location
                </Button>
            </Space>
            {list.isError && <ProblemAlert error={list.error} onRetry={() => list.refetch()} />}
            {actionError ? <ProblemAlert error={actionError} /> : null}
            <Table<Location>
                rowKey="id"
                size="medium"
                columns={columns}
                dataSource={list.data}
                loading={list.isFetching}
                pagination={false}
                scroll={{ x: 640 }}
                locale={{ emptyText: <Empty description="No locations yet" /> }}
            />
            {editor && (
                <LocationEditor
                    target={editor}
                    onClose={() => setEditor(null)}
                    onSaved={async (name) => {
                        message.success(`Saved ${name}`);
                        setEditor(null);
                        await queryClient.invalidateQueries({ queryKey: ['locations'] });
                    }}
                />
            )}
        </>
    );
}

function LocationEditor({
    target,
    onClose,
    onSaved,
}: {
    target: Location | 'new';
    onClose: () => void;
    onSaved: (name: string) => Promise<void>;
}) {
    const [form] = Form.useForm<Schemas['LocationWrite']>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const editing = target === 'new' ? null : target;

    const onFinish = async (values: Schemas['LocationWrite']) => {
        const body: Schemas['LocationWrite'] = {
            code: values.code.trim(),
            name: values.name.trim(),
        };
        setSaving(true);
        setError(null);
        try {
            if (editing) {
                await unwrap(
                    api.PATCH('/locations/{locationId}', {
                        params: { path: { locationId: editing.id } },
                        body,
                    }),
                );
            } else {
                await unwrap(api.POST('/locations', { body }));
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
            title={editing ? `Edit ${editing.name}` : 'Add location'}
            okText="Save"
            confirmLoading={saving}
            onOk={() => form.submit()}
            onCancel={onClose}
            destroyOnHidden
        >
            {error ? <ProblemAlert error={error} /> : null}
            <Form<Schemas['LocationWrite']>
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
