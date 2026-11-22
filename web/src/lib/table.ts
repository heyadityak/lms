'use client';

import type { TableProps } from 'antd';
import type { SortOrder } from 'antd/es/table/interface';
import { useState } from 'react';
import type { Schemas } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

export type Sort = components['parameters']['SortLeaveRequests'];
type SortField = 'start_date' | 'created_at' | 'days';

/** Server-side page + sort state → `page[number]`, `page[size]`, `sort` (API_GUIDELINES §5). */
export function useTableQuery(defaultSort: Sort, defaultSize = 10) {
    const [page, setPage] = useState(1);
    const [size, setSize] = useState(defaultSize);
    const [sort, setSort] = useState<Sort>(defaultSort);

    const onChange: TableProps<Schemas['LeaveRequest']>['onChange'] = (p, _f, sorter) => {
        const s = Array.isArray(sorter) ? sorter[0] : sorter;
        const field = s?.columnKey as SortField | undefined;
        const next: Sort =
            field && s.order
                ? ((s.order === 'descend' ? `-${field}` : field) as Sort)
                : defaultSort;
        if (next !== sort) {
            setSort(next);
            setPage(1);
        } else {
            setPage(p.current ?? 1);
        }
        setSize(p.pageSize ?? size);
    };

    const sortOrder = (field: SortField): SortOrder =>
        sort === field ? 'ascend' : sort === `-${field}` ? 'descend' : null;

    return {
        query: { sort, 'page[number]': page, 'page[size]': size },
        onChange,
        sortOrder,
        resetPage: () => setPage(1),
        pagination: (total: number | undefined): TableProps['pagination'] =>
            pageOf(page, size, total),
    };
}

/** Page state for tables whose sort is not the leave-request enum. */
export function usePager(defaultSize = 10) {
    const [page, setPage] = useState(1);
    const [size, setSize] = useState(defaultSize);
    return {
        page,
        size,
        reset: () => setPage(1),
        apply: (current?: number, pageSize?: number) => {
            setPage(current ?? 1);
            if (pageSize && pageSize !== size) setSize(pageSize);
        },
        pagination: (total: number | undefined): TableProps['pagination'] =>
            pageOf(page, size, total),
    };
}

function pageOf(page: number, size: number, total: number | undefined): TableProps['pagination'] {
    return {
        current: page,
        pageSize: size,
        total,
        showSizeChanger: true,
        pageSizeOptions: [10, 25, 50],
        showTotal: (t, [a, b]) => `${a}–${b} of ${t}`,
        placement: ['bottomEnd'],
    };
}
