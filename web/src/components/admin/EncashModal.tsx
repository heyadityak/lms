'use client';

import { Form, Input, InputNumber, Modal, Select } from 'antd';
import { useState } from 'react';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useAllLeaveTypes } from '@/lib/queries';
import { ProblemAlert } from '../ui';

interface Values {
    leave_type_id?: string;
    days: number | null;
    note: string;
}

/** Pay out leave days for one person. The note is required. */
export function EncashModal({
    userId,
    userName,
    onClose,
    onSaved,
}: {
    userId: string;
    userName: string;
    onClose: () => void;
    onSaved: () => Promise<void>;
}) {
    const [form] = Form.useForm<Values>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const types = useAllLeaveTypes();
    const choices = (types.data ?? []).filter((type) => type.tracks_balance);

    const onFinish = async (values: Values) => {
        if (!values.leave_type_id) return;
        const body: Schemas['EncashmentCreate'] = {
            leave_type_id: values.leave_type_id,
            days: values.days ?? 0,
            note: values.note.trim(),
        };
        setSaving(true);
        setError(null);
        try {
            await unwrap(
                api.POST('/users/{userId}/encashments', {
                    params: { path: { userId } },
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
            title={`Encash leave for ${userName}`}
            okText="Encash"
            confirmLoading={saving}
            onOk={() => form.submit()}
            onCancel={onClose}
            destroyOnHidden
        >
            {error ? <ProblemAlert error={error} /> : null}
            <Form<Values> form={form} layout="vertical" onFinish={onFinish}>
                <Form.Item
                    name="leave_type_id"
                    label="Leave type"
                    rules={[{ required: true, message: 'Choose a leave type' }]}
                >
                    <Select
                        loading={types.isFetching}
                        options={choices.map((type) => ({
                            value: type.id,
                            label: `${type.name} (${type.code})`,
                        }))}
                    />
                </Form.Item>
                <Form.Item
                    name="days"
                    label="Days"
                    extra="Positive. Cannot exceed the available balance."
                    rules={[
                        {
                            validator: async (_, value: number | null | undefined) => {
                                if (value == null || value <= 0) {
                                    throw new Error('Enter the number of days');
                                }
                            },
                        },
                    ]}
                >
                    <InputNumber min={0.5} step={0.5} style={{ width: '100%' }} />
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
