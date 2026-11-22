import { notFound } from 'next/navigation';
import { WorkingWeekView } from '@/components/admin/WorkingWeekView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Working week' };

export default async function WorkingWeekPage() {
    const me = await getMe();
    if (me.role !== 'hr_admin') notFound();
    return <WorkingWeekView />;
}
