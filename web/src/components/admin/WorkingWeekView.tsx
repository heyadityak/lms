'use client';

import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Checkbox, Form, Skeleton, Space, Typography } from 'antd';
import { useState } from 'react';
import { browserApi as api, unwrap } from '@/lib/api/client';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { useWorkingWeek } from '@/lib/queries';
import { WEEKDAYS, weekendSelectionError } from '@/lib/weekend';
import { PageTitle, ProblemAlert } from '../ui';

interface Values {
    weekend_days?: number[];
}

/**
 * Working week (UC-H16). Invalid sets are rejected here, before PATCH.
 * `null` resets to `LMS_WEEKEND_DAYS`. A missing API (phase 15) stays on this page.
 */
export function WorkingWeekView() {
    const week = useWorkingWeek();
    if (week.isPending) {
        return (
            <>
                <PageTitle>Working week</PageTitle>
                <Skeleton active />
            </>
        );
    }
    if (week.isError || !week.data || !Array.isArray(week.data.weekend_days)) {
        return (
            <>
                <PageTitle>Working week</PageTitle>
                <ProblemAlert error={week.error} onRetry={() => week.refetch()} />
            </>
        );
    }
    return (
        <WorkingWeekForm
            key={`${week.data.overridden}:${[...week.data.weekend_days].sort((a, b) => a - b).join(',')}`}
            initialDays={week.data.weekend_days}
            overridden={week.data.overridden}
        />
    );
}

export function WorkingWeekForm({
    initialDays,
    overridden,
}: {
    initialDays: number[];
    overridden: boolean;
}) {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const [form] = Form.useForm<Values>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);

    const send = async (weekend_days: number[] | null) => {
        setSaving(true);
        setError(null);
        try {
            await unwrap(api.PATCH('/settings/working-week', { body: { weekend_days } }));
            message.success(
                weekend_days === null
                    ? 'Weekend reset to the deployment default'
                    : 'Working week saved. It applies to leave submitted after this.',
            );
            await queryClient.invalidateQueries({ queryKey: ['working-week'] });
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <PageTitle>Working week</PageTitle>
            <Typography.Paragraph>
                {overridden
                    ? 'Custom weekend. This overrides the deployment default.'
                    : 'Using the deployment default.'}{' '}
                Already submitted requests keep the day count they were given.
            </Typography.Paragraph>
            {error ? <ProblemAlert error={error} /> : null}
            <Form<Values>
                form={form}
                layout="vertical"
                initialValues={{ weekend_days: initialDays }}
                onFinish={(values) => {
                    const days = values.weekend_days ?? [];
                    const invalid = weekendSelectionError(days);
                    if (invalid) {
                        form.setFields([{ name: 'weekend_days', errors: [invalid] }]);
                        return;
                    }
                    void send([...days].sort((a, b) => a - b));
                }}
                style={{ maxWidth: 560 }}
            >
                <Form.Item
                    name="weekend_days"
                    label="Weekend days"
                    extra="Days that do not count as leave. Leaving every day unchecked means a 7-day working week."
                    rules={[
                        {
                            validator: async (_, value: number[] | undefined) => {
                                const invalid = weekendSelectionError(value ?? []);
                                if (invalid) throw new Error(invalid);
                            },
                        },
                    ]}
                >
                    <Checkbox.Group options={WEEKDAYS} style={{ display: 'grid', gap: 8 }} />
                </Form.Item>
                <Space wrap>
                    <Button type="primary" htmlType="submit" loading={saving}>
                        Save
                    </Button>
                    <Button htmlType="button" onClick={() => void send(null)} disabled={saving}>
                        Reset to default
                    </Button>
                </Space>
            </Form>
        </>
    );
}
