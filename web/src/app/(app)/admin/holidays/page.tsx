import { notFound } from 'next/navigation';
import { HolidaysAdminView } from '@/components/admin/HolidaysAdminView';
import { getMe } from '@/lib/me';
import { getBranding } from '@/theme/branding';

export const metadata = { title: 'Holiday calendar' };

export default async function HolidayCalendarPage() {
    const [me, brand] = await Promise.all([getMe(), getBranding()]);
    if (me.role !== 'hr_admin' || !brand.modules.includes('holidays')) notFound();
    return <HolidaysAdminView />;
}
