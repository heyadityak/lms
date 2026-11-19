'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, App, Button, Checkbox, DatePicker, Form, Input, Modal, Radio, Space, Spin } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useRef, useState } from 'react';
import { ApiError, browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { useLeaveTypes } from '@/lib/queries';
import { FIGMA } from '@/theme/brand';
import { ProblemAlert, fmtDays } from './ui';

type Body = Schemas['LeaveRequestCreate'];

interface Values {
    leave_type_id?: string;
    dates?: [Dayjs | null, Dayjs | null] | null;
    half_day?: boolean;
    day_part?: 'first_half' | 'second_half';
    reason?: string;
    handover_note?: string;
}

// API field → form field (problem+json `errors` keys, API_GUIDELINES §4).
const FIELD: Record<string, keyof Values> = {
    leave_type_id: 'leave_type_id',
    start_date: 'dates',
    end_date: 'dates',
    day_part: 'half_day',
    reason: 'reason',
    handover_note: 'handover_note',
};

const PREVIEW_REASON = 'Preview'; // reason does not affect the preview; V9 needs ≥ 3 chars.

function toBody(v: Values, forPreview = false): Body | null {
    const [from, to] = v.dates ?? [];
    if (!v.leave_type_id || !from || !to) return null;
    const reason = v.reason?.trim() ?? '';
    return {
        leave_type_id: v.leave_type_id,
        start_date: from.format('YYYY-MM-DD'),
        end_date: to.format('YYYY-MM-DD'),
        day_part: v.half_day ? (v.day_part ?? 'first_half') : 'full',
        reason: forPreview && reason.length < 3 ? PREVIEW_REASON : reason,
        ...(!forPreview && v.handover_note?.trim() ? { handover_note: v.handover_note.trim() } : {}),
    };
}

function useDebounced<T>(value: T, ms: number): T {
    const [debounced, setDebounced] = useState(value);
    const key = JSON.stringify(value);
    useEffect(() => {
        const t = setTimeout(() => setDebounced(JSON.parse(key) as T), ms);
        return () => clearTimeout(t);
    }, [key, ms]);
    return debounced;
}

export function ApplyLeaveModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    return (
        <Modal
            open={open}
            onCancel={onClose}
            title="Apply Leave"
            footer={null}
            width={720}
            destroyOnHidden
            styles={{
                container: { padding: 0, overflow: 'hidden' },
                header: { background: FIGMA.navy, padding: '16px 32px', margin: 0 },
                title: { color: '#FFFFFF', fontSize: 20 },
                close: { color: '#FFFFFF', top: 16 },
                body: { padding: 0 },
            }}
        >
            <ApplyLeaveForm onDone={onClose} />
        </Modal>
    );
}

/** Mounted per modal open (destroyOnHidden), so each open gets fresh idempotency state. */
function ApplyLeaveForm({ onDone }: { onDone: () => void }) {
    const [form] = Form.useForm<Values>();
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const types = useLeaveTypes();
    const attempt = useRef<{ body: string; key: string } | null>(null);
    const [submitError, setSubmitError] = useState<unknown>(null);

    const typeId = Form.useWatch('leave_type_id', form);
    const dates = Form.useWatch('dates', form);
    const halfDay = Form.useWatch('half_day', form);
    const dayPart = Form.useWatch('day_part', form);
    const reason = Form.useWatch('reason', form);
    const type = types.data?.find((t) => t.id === typeId);
    const singleDay = !!dates?.[0] && !!dates?.[1] && dates[0].isSame(dates[1], 'day');
    const halfDayAllowed = !!type?.allow_half_day && singleDay;

    useEffect(() => {
        if (!typeId && types.data?.[0]) form.setFieldValue('leave_type_id', types.data[0].id);
    }, [typeId, types.data, form]);
    useEffect(() => {
        if (halfDay && !halfDayAllowed) form.setFieldValue('half_day', false);
    }, [halfDay, halfDayAllowed, form]);

    const previewBody = useDebounced(
        toBody({ leave_type_id: typeId, dates, half_day: halfDay, day_part: dayPart, reason }, true),
        300,
    );
    const preview = useQuery({
        queryKey: ['preview', previewBody],
        queryFn: async () =>
            (await unwrap(api.POST('/leave-requests/preview', { body: previewBody! }))).data,
        enabled: previewBody !== null,
        retry: false,
        staleTime: 0,
        gcTime: 0,
    });

    const submit = useMutation({
        mutationFn: ({ body, key }: { body: Body; key: string }) =>
            unwrap(
                api.POST('/leave-requests', {
                    body,
                    params: { header: { 'Idempotency-Key': key } },
                }),
            ),
    });

    const onFinish = async (values: Values) => {
        const body = toBody(values);
        if (!body) return;
        // Same body → same key, so a retry after a lost response is not a second request.
        // A changed body needs a new key (the server pins the key to the first body, incl. 422s).
        const json = JSON.stringify(body);
        if (attempt.current?.body !== json) attempt.current = { body: json, key: crypto.randomUUID() };
        setSubmitError(null);
        try {
            await submit.mutateAsync({ body, key: attempt.current.key });
            message.success('Leave request submitted');
            await queryClient.invalidateQueries();
            onDone();
        } catch (e) {
            setSubmitError(e);
            if (e instanceof ApiError && e.problem.errors) {
                const byField = new Map<keyof Values, string[]>();
                for (const [k, msgs] of Object.entries(e.problem.errors)) {
                    const f = FIELD[k];
                    if (f) byField.set(f, [...(byField.get(f) ?? []), ...msgs]);
                }
                form.setFields([...byField].map(([name, errors]) => ({ name, errors })));
            }
        }
    };

    const minDate = type ? dayjs().startOf('day').subtract(type.max_backdate_days, 'day') : null;
    const p = preview.data;

    if (types.isPending) return <Spin style={{ display: 'block', padding: 48 }} />;
    if (types.isError) return <div style={{ padding: 24 }}><ProblemAlert error={types.error} /></div>;

    return (
        <Form<Values>
            form={form}
            onFinish={onFinish}
            layout="horizontal"
            labelCol={{ xs: { span: 24 }, sm: { flex: '130px' } }}
            wrapperCol={{ xs: { span: 24 }, sm: { flex: 1 } }}
            labelAlign="left"
            colon
            requiredMark={false}
        >
            <div style={{ padding: '24px 32px 8px' }}>
                <Form.Item
                    name="leave_type_id"
                    label="Leave type"
                    rules={[{ required: true, message: 'Choose a leave type' }]}
                >
                    <Radio.Group options={types.data.map((t) => ({ value: t.id, label: t.name }))} />
                </Form.Item>
                <Form.Item
                    name="dates"
                    label="Dates"
                    extra="Format YYYY-MM-DD"
                    rules={[{ required: true, message: 'Choose the first and last day' }]}
                >
                    <DatePicker.RangePicker
                        format="YYYY-MM-DD"
                        placeholder={['From', 'To']}
                        disabledDate={(d) => (minDate ? d.isBefore(minDate, 'day') : false)}
                        style={{ width: '100%', maxWidth: 360 }}
                    />
                </Form.Item>
                <Form.Item label="Half day" required={false}>
                    <Space wrap>
                        <Form.Item name="half_day" valuePropName="checked" noStyle>
                            <Checkbox disabled={!halfDayAllowed} aria-label="Half day">
                                {halfDayAllowed ? '' : 'Single day only'}
                            </Checkbox>
                        </Form.Item>
                        {halfDay && (
                            <Form.Item name="day_part" initialValue="first_half" noStyle>
                                <Radio.Group
                                    aria-label="Which half"
                                    options={[
                                        { value: 'first_half', label: 'First half' },
                                        { value: 'second_half', label: 'Second half' },
                                    ]}
                                />
                            </Form.Item>
                        )}
                    </Space>
                </Form.Item>
                <Form.Item label="No. of days">
                    <div aria-live="polite" data-testid="preview">
                        {preview.isFetching ? (
                            <Spin size="small" />
                        ) : p ? (
                            <Space orientation="vertical" size={0}>
                                <strong>{fmtDays(p.days)}</strong>
                                <span style={{ color: FIGMA.text }}>
                                    {p.tracks_balance
                                        ? `Available balance ${p.available_before} → ${p.available_after}`
                                        : 'Not deducted from your balance'}
                                </span>
                            </Space>
                        ) : (
                            <span style={{ color: FIGMA.text }}>Pick dates to see the day count</span>
                        )}
                    </div>
                </Form.Item>
                {preview.isError && (
                    <Form.Item wrapperCol={{ span: 24 }}>
                        <PreviewError error={preview.error} />
                    </Form.Item>
                )}
                <Form.Item
                    name="reason"
                    label="Reason"
                    rules={[
                        { required: true, message: 'Enter a reason' },
                        { min: 3, message: 'At least 3 characters' },
                    ]}
                >
                    <Input.TextArea maxLength={1000} showCount rows={3} placeholder="e.g. Family trip" />
                </Form.Item>
                <Form.Item name="handover_note" label="Handover note">
                    <Input.TextArea maxLength={2000} rows={2} placeholder="Optional" />
                </Form.Item>
                {submitError !== null && (
                    <Form.Item wrapperCol={{ span: 24 }}>
                        <ProblemAlert error={submitError} />
                    </Form.Item>
                )}
            </div>
            <div
                style={{
                    background: FIGMA.page,
                    padding: '20px 32px',
                    display: 'flex',
                    justifyContent: 'flex-end',
                    gap: 24,
                }}
            >
                <Button type="primary" htmlType="submit" loading={submit.isPending} style={{ minWidth: 88 }}>
                    Apply
                </Button>
                <Button
                    onClick={onDone}
                    style={{ minWidth: 88, borderColor: FIGMA.navy, color: FIGMA.navy, borderWidth: 1.5 }}
                >
                    Cancel
                </Button>
            </div>
        </Form>
    );
}

/** Inline rule error from the preview (problem `code`/`title`/`errors`). */
function PreviewError({ error }: { error: unknown }) {
    if (!(error instanceof ApiError) || error.status !== 422) return <ProblemAlert error={error} />;
    const msgs = Object.entries(error.problem.errors ?? {})
        .filter(([k]) => k !== 'reason')
        .flatMap(([, m]) => m);
    return (
        <Alert
            type="warning"
            showIcon
            title={error.problem.title ?? 'This request would be refused'}
            description={msgs.length ? msgs.join(' ') : error.problem.detail}
            data-code={error.problem.code}
        />
    );
}
