'use client';

import { DatePicker } from 'antd';
import { forwardRef } from 'react';
import { FIGMA } from '@/theme/brand';

type RangePickerProps = React.ComponentProps<typeof DatePicker.RangePicker>;

/** Range picker with a visible label on each input. Placeholders alone fail axe. */
export const DateRangeField = forwardRef<
    React.ComponentRef<typeof DatePicker.RangePicker>,
    RangePickerProps & { startLabel: string; endLabel: string }
>(function DateRangeField({ id, startLabel, endLabel, style, ...rest }, ref) {
    const startId = typeof id === 'string' ? id : (id?.start ?? 'date-start');
    const endId = typeof id === 'string' ? `${id}-end` : (id?.end ?? `${startId}-end`);
    const labelStyle = { fontWeight: 600, color: FIGMA.heading } as const;
    return (
        <div style={{ display: 'inline-grid', maxWidth: '100%', rowGap: 4 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', columnGap: 16 }}>
                <label htmlFor={startId} style={labelStyle}>
                    {startLabel}
                </label>
                <label htmlFor={endId} style={labelStyle}>
                    {endLabel}
                </label>
            </div>
            <DatePicker.RangePicker
                {...rest}
                ref={ref}
                id={{ start: startId, end: endId }}
                style={style}
            />
        </div>
    );
});
