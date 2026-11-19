'use client';

import { Form, Input, Modal } from 'antd';
import { useState } from 'react';

/** Confirm with a comment: required for reject, optional for cancel (UI_GUIDELINES §3). */
export function CommentModal({
    open,
    title,
    okText,
    required,
    onSubmit,
    onClose,
}: {
    open: boolean;
    title: string;
    okText: string;
    required: boolean;
    onSubmit: (comment: string | null) => Promise<void>;
    onClose: () => void;
}) {
    const [form] = Form.useForm<{ comment?: string }>();
    const [busy, setBusy] = useState(false);

    const onOk = async () => {
        const { comment } = await form.validateFields();
        setBusy(true);
        try {
            await onSubmit(comment?.trim() || null);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open={open}
            title={title}
            okText={okText}
            okButtonProps={{ danger: true, loading: busy }}
            cancelText="Back"
            onOk={onOk}
            onCancel={onClose}
            destroyOnHidden
        >
            <Form form={form} layout="vertical" preserve={false}>
                <Form.Item
                    name="comment"
                    label={required ? 'Comment' : 'Comment (optional)'}
                    rules={
                        required
                            ? [
                                  { required: true, whitespace: true, message: 'A comment is required' },
                                  { min: 3, message: 'At least 3 characters' },
                              ]
                            : []
                    }
                >
                    <Input.TextArea rows={3} maxLength={1000} showCount autoFocus />
                </Form.Item>
            </Form>
        </Modal>
    );
}
