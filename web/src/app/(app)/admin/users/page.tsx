import { notFound } from 'next/navigation';
import { UsersView } from '@/components/admin/UsersView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Users' };

export default async function UsersPage() {
    const me = await getMe();
    if (me.role !== 'hr_admin') notFound();
    return <UsersView />;
}
