import { LeavesView } from '@/components/LeavesView';

export const metadata = { title: 'Apply leave' };

/** Deep link for the Apply Leave modal (UI_GUIDELINES S4). */
export default function NewLeavePage() {
    return <LeavesView applyOpen />;
}
