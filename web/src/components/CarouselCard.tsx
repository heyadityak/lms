'use client';

import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { Button, Card, Skeleton } from 'antd';
import { useState, type ReactNode } from 'react';
import { FIGMA } from '@/theme/brand';
import { ProblemAlert } from './ui';

/** Figma carousel card (Team Time Off, Next Holidays): ‹ title › header, one page at a time. */
export function CarouselCard({
    title,
    pages,
    loading,
    error,
    empty,
}: {
    title: string;
    pages: ReactNode[];
    loading?: boolean;
    error?: unknown;
    empty: string;
}) {
    const [index, setIndex] = useState(0);
    const i = Math.min(index, Math.max(pages.length - 1, 0));
    const arrow = (dir: -1 | 1) => (
        <Button
            type="text"
            icon={dir < 0 ? <LeftOutlined /> : <RightOutlined />}
            aria-label={`${dir < 0 ? 'Previous' : 'Next'}: ${title}`}
            disabled={dir < 0 ? i === 0 : i >= pages.length - 1}
            onClick={() => setIndex(i + dir)}
            style={{ height: 52, width: 52, borderRadius: 0, color: FIGMA.primary, fontSize: 20 }}
        />
    );
    const divider = { borderInline: `1px solid ${FIGMA.subtle}` };

    return (
        <Card styles={{ body: { padding: 0 } }} style={{ height: '100%' }} className="lms-carousel">
            <section aria-label={title}>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        borderBottom: `1px solid ${FIGMA.subtle}`,
                    }}
                >
                    {arrow(-1)}
                    <h2
                        style={{
                            ...divider,
                            flex: 1,
                            margin: 0,
                            textAlign: 'center',
                            fontSize: 16,
                            fontWeight: 600,
                            lineHeight: '52px',
                            color: FIGMA.heading,
                        }}
                    >
                        {title}
                    </h2>
                    {arrow(1)}
                </div>
                <div style={{ padding: 16, minHeight: 96 }} aria-live="polite">
                    {loading ? (
                        <Skeleton active paragraph={{ rows: 2 }} />
                    ) : error ? (
                        <ProblemAlert error={error} />
                    ) : pages.length ? (
                        pages[i]
                    ) : (
                        <p style={{ color: FIGMA.text, textAlign: 'center', margin: '24px 0' }}>
                            {empty}
                        </p>
                    )}
                </div>
            </section>
        </Card>
    );
}
