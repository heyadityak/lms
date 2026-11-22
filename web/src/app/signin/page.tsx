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
        <main className="lms-signin">
            <SignInCard
                name={brand.name}
                logoUrl={brand.logo_url}
                primaryColor={brand.primary_color}
                primaryContrast={brand.primary_contrast}
            />
        </main>
    );
}
