import { notFound } from 'next/navigation';
import { DepartmentsView } from '@/components/admin/DepartmentsView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Departments' };

export default async function DepartmentsPage() {
    const me = await getMe();
    if (me.role !== 'hr_admin') notFound();
    return <DepartmentsView />;
}
