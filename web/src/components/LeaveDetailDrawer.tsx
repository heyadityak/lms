'use client';

import { useQuery } from '@tanstack/react-query';
import { Descriptions, Drawer, Skeleton, Timeline, Typography } from 'antd';
import dayjs from 'dayjs';
import { browserApi as api, unwrap } from '@/lib/api/client';
import { ProblemAlert, StatusTag, fmtDate, fmtDays } from './ui';

const DAY_PART = { full: 'Full day', first_half: 'First half', second_half: 'Second half' };
const ACTION = {
    submitted: 'Submitted',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/** Request detail + action history (`include=actions`), UI_GUIDELINES S5. */
export function LeaveDetailDrawer({ id, onClose }: { id: string | null; onClose: () => void }) {
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
    const r = q.data;

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
                            { key: 'days', label: 'Days', children: fmtDays(r.days) },
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
