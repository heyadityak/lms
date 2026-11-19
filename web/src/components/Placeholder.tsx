'use client';

import { Typography } from 'antd';

/** shortcut: placeholder until the real screen lands in phase 12. */
export function Placeholder({ title }: { title: string }) {
    return (
        <>
            <Typography.Title level={2}>{title}</Typography.Title>
            <Typography.Paragraph>This screen is coming soon.</Typography.Paragraph>
        </>
    );
}
