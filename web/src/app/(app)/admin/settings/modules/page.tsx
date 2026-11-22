import { notFound } from 'next/navigation';
import { ModulesView } from '@/components/admin/ModulesView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Modules' };

export default async function ModulesPage() {
    const me = await getMe();
    if (!me.is_admin) notFound();
    return <ModulesView />;
}
