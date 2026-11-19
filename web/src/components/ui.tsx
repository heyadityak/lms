'use client';

import { Alert, Avatar, Button, Card, Skeleton, Tag, Tooltip, Typography } from 'antd';
import dayjs from 'dayjs';
import type { ReactNode } from 'react';
import { ApiError, type Schemas } from '@/lib/api/client';
import { FIGMA } from '@/theme/brand';

/** Figma tile/tag palette; every pair has ≥ 4.5:1 contrast (WCAG 2.2 AA). */
export const TONES = {
    success: { background: '#D1FAE5', color: '#064E3B' },
    danger: { background: '#FFE3E3', color: '#B91C1C' },
    warning: { background: '#FDE68A', color: '#713F12' },
    neutral: { background: FIGMA.subtle, color: FIGMA.heading },
} as const;
export type Tone = keyof typeof TONES;

const STATUS_TONE: Record<Schemas['LeaveStatus'], Tone> = {
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

export const STATUS_LABEL: Record<Schemas['LeaveStatus'], string> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export function StatusTag({ status }: { status: Schemas['LeaveStatus'] }) {
    const tone = TONES[STATUS_TONE[status]];
    return (
        <Tag
            variant="filled"
            style={{ ...tone, minWidth: 96, textAlign: 'center', paddingBlock: 4, fontSize: 14 }}
        >
            {STATUS_LABEL[status]}
        </Tag>
    );
}

export const fmtDate = (d: string) => dayjs(d).format('DD MMM YYYY');
export const fmtDays = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`;

export const isApprover = (role: Schemas['Role']) => role === 'manager' || role === 'hr_admin';

export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts.at(-1)?.[0] ?? '') : '')).toUpperCase();
}

/** Initials avatar (no Figma photos: license unresolved). */
export function InitialsAvatar({
    name,
    label = name,
    size = 32,
}: {
    name: string;
    label?: string;
    size?: number;
}) {
    return (
        <Tooltip title={label}>
            <Avatar
                size={size}
                style={{ background: FIGMA.heading, color: '#FFFFFF', fontSize: size * 0.4 }}
                aria-label={label}
                role="img"
            >
                {initials(name)}
            </Avatar>
        </Tooltip>
    );
}

export function PageTitle({ children }: { children: ReactNode }) {
    return (
        <Typography.Title level={1} style={{ fontSize: 20, fontWeight: 600, margin: '8px 0 24px' }}>
            {children}
        </Typography.Title>
    );
}

/** Figma stat card: coloured number tile, two-line title, optional action on the right. */
export function StatCard({
    value,
    unit,
    title,
    tone,
    action,
    loading,
    compact,
}: {
    value: ReactNode;
    unit?: string;
    title: ReactNode;
    tone: Tone;
    action?: ReactNode;
    loading?: boolean;
    compact?: boolean;
}) {
    const long = String(value).length > 2;
    return (
        <Card styles={{ body: { padding: compact ? 16 : 22 } }} style={{ height: '100%' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 24 }}>
                <div
                    style={{
                        ...TONES[tone],
                        width: 72,
                        height: 72,
                        flex: 'none',
                        borderRadius: 3,
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                >
                    {loading ? (
                        <Skeleton.Button active size="small" />
                    ) : (
                        <span
                            style={{
                                fontSize: long ? 28 : unit ? 44 : 40,
                                lineHeight: 1,
                                fontWeight: 600,
                            }}
                        >
                            {value}
                        </span>
                    )}
                    {unit && (
                        <span style={{ fontSize: 12, textTransform: 'uppercase', marginTop: 2 }}>
                            {unit}
                        </span>
                    )}
                </div>
                <div
                    style={{
                        flex: 1,
                        fontSize: compact ? 18 : 20,
                        fontWeight: 600,
                        lineHeight: 1.3,
                        color: FIGMA.heading,
                    }}
                >
                    {title}
                </div>
                {action}
            </div>
        </Card>
    );
}

/** problem+json → error state (title, detail, request id for support). */
export function ProblemAlert({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
    const p = error instanceof ApiError ? error.problem : {};
    return (
        <Alert
            type="error"
            showIcon
            title={p.title ?? 'Something went wrong'}
            description={
                <>
                    {p.detail ?? (error instanceof Error ? error.message : 'Please try again.')}
                    {p.request_id && (
                        <Typography.Text type="secondary"> (request {p.request_id})</Typography.Text>
                    )}
                </>
            }
            action={
                onRetry && (
                    <Button size="small" onClick={onRetry}>
                        Retry
                    </Button>
                )
            }
        />
    );
}
