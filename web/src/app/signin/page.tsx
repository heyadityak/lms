import { redirect } from 'next/navigation';
import { SignInCard } from '@/components/SignInCard';
import { loadMe } from '@/lib/me';
import { getBranding } from '@/theme/branding';

export const metadata = { title: 'Sign in' };

export default async function SignInPage() {
    // Same check as the app shell. A session cookie getSession would accept but GET /me
    // rejects must stay on this page, or the browser loops with the protected route.
    if (await loadMe()) redirect('/');
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
