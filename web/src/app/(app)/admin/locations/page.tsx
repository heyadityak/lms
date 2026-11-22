import { notFound } from 'next/navigation';
import { LocationsView } from '@/components/admin/LocationsView';
import { getMe } from '@/lib/me';

export const metadata = { title: 'Locations' };

export default async function LocationsPage() {
    const me = await getMe();
    if (me.role !== 'hr_admin') notFound();
    return <LocationsView />;
}
