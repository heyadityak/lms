import type { ReactNode } from 'react';
import { AppShell } from '@/components/AppShell';
import { getMe } from '@/lib/me';
import { getBranding } from '@/theme/branding';

export default async function AppLayout({ children }: { children: ReactNode }) {
    const [me, brand] = await Promise.all([getMe(), getBranding()]);
    return (
        <AppShell user={me} brand={brand}>
            {children}
        </AppShell>
    );
}
