import { notFound } from 'next/navigation';
import { BrandingView } from '@/components/admin/BrandingView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Branding' };

export default async function BrandingPage() {
    const me = await getMe();
    if (!me.is_admin) notFound();
    return <BrandingView />;
}
