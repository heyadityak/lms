'use client';

import { useQuery } from '@tanstack/react-query';
import { browserApi as api, unwrap } from '@/lib/api/client';

/** Shared reads. Every mutation invalidates all queries (small app, simplest correct choice). */

export const useBalances = () =>
    useQuery({ queryKey: ['balances'], queryFn: () => unwrap(api.GET('/me/balances')) });

export const useLeaveTypes = () =>
    useQuery({
        queryKey: ['leave-types'],
        queryFn: async () => (await unwrap(api.GET('/leave-types'))).data,
        staleTime: 300_000,
    });

export const useHolidays = (enabled: boolean) =>
    useQuery({
        queryKey: ['holidays'],
        queryFn: async () => (await unwrap(api.GET('/holidays'))).data,
        enabled,
        staleTime: 300_000,
    });

export const useWorkingWeek = (enabled = true) =>
    useQuery({
        queryKey: ['working-week'],
        queryFn: async () => (await unwrap(api.GET('/settings/working-week'))).data,
        enabled,
        staleTime: 300_000,
    });

/** Pending approvals the caller may decide (meta.total of a one-row page). */
export const usePendingApprovalCount = (enabled: boolean) =>
    useQuery({
        queryKey: ['approvals', 'count'],
        queryFn: async () =>
            (await unwrap(api.GET('/approvals', { params: { query: { 'page[size]': 1 } } }))).meta
                .total,
        enabled,
    });

/** My own pending requests. */
export const useMyPendingCount = () =>
    useQuery({
        queryKey: ['my-requests', 'pending-count'],
        queryFn: async () =>
            (
                await unwrap(
                    api.GET('/me/leave-requests', {
                        params: { query: { 'filter[status]': 'pending', 'page[size]': 1 } },
                    }),
                )
            ).meta.total,
    });
