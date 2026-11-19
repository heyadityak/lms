import { headers } from 'next/headers';
import { redirect } from 'next/navigation';
import { getSession } from '@/auth';
import { SignInCard } from '@/components/SignInCard';
import { getBranding } from '@/theme/branding';

export const metadata = { title: 'Sign in' };

export default async function SignInPage() {
    if (await getSession(await headers()).catch(() => null)) redirect('/');
    const brand = await getBranding();

    return (
        <main
            style={{
                minHeight: '100vh',
                display: 'grid',
                placeItems: 'center',
                background: 'var(--ant-color-bg-layout)',
            }}
        >
            <SignInCard name={brand.name} logoUrl={brand.logo_url} />
        </main>
    );
}
