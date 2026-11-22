import { notFound } from 'next/navigation';
import { ReportsView } from '@/components/admin/ReportsView';
import { getMe } from '@/lib/me';
import { getBranding } from '@/theme/branding';

export const metadata = { title: 'Reports' };

export default async function ReportsPage() {
    const [me, brand] = await Promise.all([getMe(), getBranding()]);
    if (me.role !== 'hr_admin' || !brand.modules.includes('reports')) notFound();
    return <ReportsView />;
}
