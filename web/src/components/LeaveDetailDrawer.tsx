'use client';

import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    App,
    Button,
    Descriptions,
    Drawer,
    Popconfirm,
    Skeleton,
    Timeline,
    Typography,
} from 'antd';
import dayjs from 'dayjs';
import { ApiError, browserApi as api, unwrap, unwrapEmpty } from '@/lib/api/client';
import { useShell } from './providers';
import { ProblemAlert, StatusTag, fmtDate, fmtDays } from './ui';

function attachmentName(header: string | null): string {
    const star = /filename\*=UTF-8''([^;]+)/i.exec(header ?? '');
    if (star?.[1]) {
        try {
            return decodeURIComponent(star[1]);
        } catch {
            return star[1];
        }
    }
    const plain = /filename="?([^";]+)"?/i.exec(header ?? '');
    return plain?.[1] ?? 'Attachment';
}

const DAY_PART = { full: 'Full day', first_half: 'First half', second_half: 'Second half' };
const ACTION = {
    submitted: 'Submitted',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/** Request detail + action history (`include=actions`), UI_GUIDELINES S5. */
export function LeaveDetailDrawer({ id, onClose }: { id: string | null; onClose: () => void }) {
    const { user } = useShell();
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const q = useQuery({
        queryKey: ['leave-request', id],
        queryFn: async () =>
            (
                await unwrap(
                    api.GET('/leave-requests/{leaveRequestId}', {
                        params: { path: { leaveRequestId: id! }, query: { include: 'actions' } },
                    }),
                )
            ).data,
        enabled: id !== null,
    });
    // One file. GET is the bytes; 404 means there is no attachment.
    const attachment = useQuery({
        queryKey: ['leave-request', id, 'attachments'],
        queryFn: async () => {
            const result = await api.GET('/leave-requests/{leaveRequestId}/attachments', {
                params: { path: { leaveRequestId: id! } },
                parseAs: 'blob',
            });
            if (result.response.status === 404) return null;
            if (!result.response.ok) {
                throw new ApiError(
                    result.response.status,
                    (result.error ?? {}) as ApiError['problem'],
                );
            }
            return { fileName: attachmentName(result.response.headers.get('content-disposition')) };
        },
        enabled: id !== null,
    });
    const r = q.data;
    const canDelete = !!r && r.user.id === user.id && r.status === 'pending';

    const removeAttachment = async () => {
        if (!id || !attachment.data) return;
        try {
            await unwrapEmpty(
                api.DELETE('/leave-requests/{leaveRequestId}/attachments', {
                    params: { path: { leaveRequestId: id } },
                }),
            );
            message.success(`Removed ${attachment.data.fileName}`);
            await queryClient.invalidateQueries({
                queryKey: ['leave-request', id, 'attachments'],
            });
        } catch (e) {
            message.error(e instanceof Error ? e.message : 'Could not remove the attachment');
        }
    };

    return (
        <Drawer open={id !== null} onClose={onClose} title="Leave request" size={480}>
            {q.isPending && <Skeleton active />}
            {q.isError && <ProblemAlert error={q.error} onRetry={() => q.refetch()} />}
            {r && (
                <>
                    <Descriptions
                        column={1}
                        size="small"
                        items={[
                            { key: 'user', label: 'Employee', children: r.user.name },
                            { key: 'type', label: 'Leave type', children: r.leave_type.name },
                            { key: 'from', label: 'From', children: fmtDate(r.start_date) },
                            { key: 'to', label: 'To', children: fmtDate(r.end_date) },
                            { key: 'part', label: 'Day part', children: DAY_PART[r.day_part] },
                            ...(r.hours
                                ? [{ key: 'hours', label: 'Hours', children: String(r.hours) }]
                                : []),
                            { key: 'days', label: 'Days', children: fmtDays(r.days) },
                            {
                                key: 'pdf',
                                label: 'PDF',
                                children: (
                                    <Typography.Link
                                        href={`/bff/api/leave-requests/${r.id}/pdf`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Download PDF
                                    </Typography.Link>
                                ),
                            },
                            {
                                key: 'status',
                                label: 'Status',
                                children: <StatusTag status={r.status} />,
                            },
                            { key: 'reason', label: 'Reason', children: r.reason },
                            ...(r.handover_note
                                ? [
                                      {
                                          key: 'handover',
                                          label: 'Handover note',
                                          children: r.handover_note,
                                      },
                                  ]
                                : []),
                            ...(r.decided_by
                                ? [{ key: 'by', label: 'Decided by', children: r.decided_by.name }]
                                : []),
                            ...(r.decision_comment
                                ? [
                                      {
                                          key: 'comment',
                                          label: 'Comment',
                                          children: r.decision_comment,
                                      },
                                  ]
                                : []),
                        ]}
                    />
                    <Typography.Title level={2} style={{ fontSize: 16, marginTop: 24 }}>
                        Attachment
                    </Typography.Title>
                    {attachment.isPending && <Skeleton active paragraph={{ rows: 1 }} />}
                    {attachment.isError && (
                        <ProblemAlert
                            error={attachment.error}
                            onRetry={() => attachment.refetch()}
                        />
                    )}
                    {attachment.data === null && (
                        <Typography.Text type="secondary">No attachment</Typography.Text>
                    )}
                    {attachment.data && (
                        <div>
                            <Typography.Link
                                href={`/bff/api/leave-requests/${r.id}/attachments`}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {attachment.data.fileName}
                            </Typography.Link>
                            {canDelete && (
                                <Popconfirm
                                    title={`Delete ${attachment.data.fileName}?`}
                                    okText="Delete attachment"
                                    okButtonProps={{ danger: true }}
                                    cancelText="Back"
                                    onConfirm={removeAttachment}
                                >
                                    <Button
                                        type="link"
                                        danger
                                        aria-label={`Delete ${attachment.data.fileName}`}
                                    >
                                        Delete
                                    </Button>
                                </Popconfirm>
                            )}
                        </div>
                    )}
                    <Typography.Title level={2} style={{ fontSize: 16, marginTop: 24 }}>
                        History
                    </Typography.Title>
                    <Timeline
                        items={(r.actions ?? []).map((a) => ({
                            key: a.id,
                            title: `${ACTION[a.action]} by ${a.actor.name}`,
                            content: (
                                <>
                                    <Typography.Text type="secondary">
                                        {dayjs(a.created_at).format('DD MMM YYYY HH:mm')}
                                    </Typography.Text>
                                    {a.comment && <div>{a.comment}</div>}
                                </>
                            ),
                        }))}
                    />
                </>
            )}
        </Drawer>
    );
}
