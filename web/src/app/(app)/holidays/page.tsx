import { notFound } from 'next/navigation';
import { HolidaysView } from '@/components/HolidaysView';
import { getBranding } from '@/theme/branding';

export const metadata = { title: 'Holidays' };

export default async function HolidaysPage() {
    if (!(await getBranding()).modules.includes('holidays')) notFound();
    return <HolidaysView />;
}
