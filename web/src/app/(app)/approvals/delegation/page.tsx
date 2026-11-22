import { notFound } from 'next/navigation';
import { DelegationView } from '@/components/DelegationView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Approval delegation' };

export default async function DelegationPage() {
    const me = await getMe();
    if (me.role !== 'manager' && me.role !== 'hr_admin') notFound();
    return <DelegationView />;
}
