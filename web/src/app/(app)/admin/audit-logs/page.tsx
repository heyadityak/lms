import { notFound } from 'next/navigation';
import { AuditLogsView } from '@/components/admin/AuditLogsView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Audit log' };

export default async function AuditLogsPage() {
    const me = await getMe();
    if (me.role !== 'hr_admin') notFound();
    return <AuditLogsView />;
}
