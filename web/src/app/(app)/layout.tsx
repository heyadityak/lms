import { headers } from 'next/headers';
import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { getAccessToken } from '@/auth';
import { AppShell } from '@/components/AppShell';
import { serverApi } from '@/lib/api/client';
import { getBranding } from '@/theme/branding';

export default async function AppLayout({ children }: { children: ReactNode }) {
    const token = await getAccessToken(await headers());
    if (!token) redirect('/signin');
    // First API call after sign-in provisions the user in Laravel (D-43 (f)).
    const [{ data: me }, brand] = await Promise.all([serverApi(token).GET('/me'), getBranding()]);
    if (!me) redirect('/signin');

    return (
        <AppShell user={me.data} brand={brand}>
            {children}
        </AppShell>
    );
}
