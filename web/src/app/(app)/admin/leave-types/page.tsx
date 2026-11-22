import { notFound } from 'next/navigation';
import { LeaveTypesView } from '@/components/admin/LeaveTypesView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Leave types' };

export default async function LeaveTypesPage() {
    const me = await getMe();
    if (me.role !== 'hr_admin') notFound();
    return <LeaveTypesView />;
}
