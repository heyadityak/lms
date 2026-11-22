'use client';

import { InfoCircleOutlined } from '@ant-design/icons';
import { Button, Popover } from 'antd';
import type { Schemas } from '@/lib/api/client';

/** Per-type numbers behind a summed stat (popover, keyboard reachable). */
export function BalanceBreakdown({
    balances,
    field,
    label,
}: {
    balances: Schemas['Balance'][];
    field: 'available' | 'used' | 'entitled';
    label: string;
}) {
    return (
        <Popover
            trigger={['click', 'hover']}
            title={label}
            content={
                <table>
                    <tbody>
                        {balances.map((b) => (
                            <tr key={b.leave_type.id}>
                                <th
                                    scope="row"
                                    style={{ textAlign: 'left', paddingRight: 16, fontWeight: 400 }}
                                >
                                    {b.leave_type.name}
                                </th>
                                <td style={{ textAlign: 'right' }}>{b[field]}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            }
        >
            <Button
                type="text"
                size="small"
                icon={<InfoCircleOutlined />}
                aria-label={`${label} per leave type`}
            />
        </Popover>
    );
}

export const sum = (
    balances: Schemas['Balance'][] | undefined,
    field: 'available' | 'used' | 'entitled',
) => (balances ?? []).reduce((n, b) => n + b[field], 0);
