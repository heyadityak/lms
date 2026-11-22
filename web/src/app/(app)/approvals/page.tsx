import { notFound } from 'next/navigation';
import { ApprovalsView } from '@/components/ApprovalsView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Approvals' };

export default async function ApprovalsPage() {
    const me = await getMe();
    if (me.role !== 'manager' && me.role !== 'hr_admin') notFound();
    return <ApprovalsView />;
}
