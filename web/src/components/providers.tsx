'use client';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createContext, useContext, useState, type ReactNode } from 'react';
import { ApiError } from '@/lib/api/client';
import type { NavUser } from './nav';

export type ShellUser = NavUser & { id: string; name: string };

const ShellContext = createContext<{ user: ShellUser; modules: readonly string[] } | null>(null);

/** Current user + enabled modules for client views (UI gate only; Laravel decides). */
export function useShell() {
    const value = useContext(ShellContext);
    if (!value) throw new Error('useShell outside AppProviders');
    return value;
}

export function newQueryClient() {
    return new QueryClient({
        defaultOptions: {
            queries: {
                staleTime: 30_000,
                refetchOnWindowFocus: false,
                // 4xx answers are final; retry network errors and 5xx twice.
                retry: (n, e) => n < 2 && !(e instanceof ApiError && e.status < 500),
            },
        },
    });
}

export function AppProviders({
    user,
    modules,
    client,
    children,
}: {
    user: ShellUser;
    modules: readonly string[];
    client?: QueryClient;
    children: ReactNode;
}) {
    const [queryClient] = useState(() => client ?? newQueryClient());
    return (
        <QueryClientProvider client={queryClient}>
            <ShellContext.Provider value={{ user, modules }}>{children}</ShellContext.Provider>
        </QueryClientProvider>
    );
}
