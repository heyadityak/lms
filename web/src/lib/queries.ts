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

/** `year` omitted → the API's current leave year. */
export const useHolidays = (enabled: boolean, year?: number) =>
    useQuery({
        queryKey: ['holidays', year ?? null],
        queryFn: async () =>
            (
                await unwrap(
                    api.GET('/holidays', {
                        params: { query: year === undefined ? {} : { 'filter[year]': year } },
                    }),
                )
            ).data,
        enabled,
        staleTime: 300_000,
    });

export const useDepartments = () =>
    useQuery({
        queryKey: ['departments'],
        queryFn: async () => (await unwrap(api.GET('/departments'))).data,
        staleTime: 60_000,
    });

export const useLocations = () =>
    useQuery({
        queryKey: ['locations'],
        queryFn: async () => (await unwrap(api.GET('/locations'))).data,
        staleTime: 60_000,
    });

/** Active and inactive types (hr_admin). `useLeaveTypes` stays the active list. */
export const useAllLeaveTypes = () =>
    useQuery({
        queryKey: ['leave-types', 'all'],
        queryFn: async () => {
            const [active, inactive] = await Promise.all([
                unwrap(
                    api.GET('/leave-types', { params: { query: { 'filter[is_active]': true } } }),
                ),
                unwrap(
                    api.GET('/leave-types', { params: { query: { 'filter[is_active]': false } } }),
                ),
            ]);
            const byId = new Map<string, (typeof active.data)[number]>();
            for (const row of [...active.data, ...inactive.data]) byId.set(row.id, row);
            return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));
        },
        staleTime: 60_000,
    });

/** Effective weekend (UC-H16). Team Time Off uses `weekend_days` once this succeeds. */
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
