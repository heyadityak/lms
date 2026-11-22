import { notFound } from 'next/navigation';
import { IdentityUsersView } from '@/components/admin/IdentityUsersView';
import { getMe } from '@/lib/me';
import { getBranding } from '@/theme/branding';

export const metadata = { title: 'Identity users' };

export default async function IdentityUsersPage() {
    const [me, brand] = await Promise.all([getMe(), getBranding()]);
    if (!me.is_admin || !brand.modules.includes('user_management')) notFound();
    return <IdentityUsersView currentUserId={me.id} />;
}
