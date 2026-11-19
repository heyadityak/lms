import type { Schemas } from '@/lib/api/client';

export interface NavUser {
    role: Schemas['Role'];
    is_admin: boolean;
}

export interface NavItem {
    key: string;
    label: string;
    children?: NavItem[];
}

type Rule = NavItem & {
    roles?: Schemas['Role'][];
    admin?: boolean;
    module?: Schemas['ModuleKey'];
};

const HR: Schemas['Role'][] = ['hr_admin'];

// UI gate only: Laravel Policies decide (SYSTEM_DESIGN §6.1, UI_GUIDELINES §2).
const MAIN: Rule[] = [
    { key: '/', label: 'Dashboard' },
    { key: '/leave-requests', label: 'Leaves' },
    { key: '/approvals', label: 'Approvals', roles: ['manager', 'hr_admin'] },
    { key: '/holidays', label: 'Holidays', module: 'holidays' },
];

const ADMIN: Rule[] = [
    { key: '/admin/users', label: 'Users', roles: HR },
    { key: '/admin/departments', label: 'Departments', roles: HR },
    { key: '/admin/leave-types', label: 'Leave types', roles: HR },
    { key: '/admin/holidays', label: 'Holiday calendar', roles: HR, module: 'holidays' },
    { key: '/admin/reports', label: 'Reports', roles: HR, module: 'reports' },
    { key: '/admin/settings/working-week', label: 'Working week', roles: HR },
    { key: '/admin/settings/branding', label: 'Branding', admin: true },
    { key: '/admin/settings/modules', label: 'Modules', admin: true },
    {
        key: '/admin/identity-users',
        label: 'Identity users',
        admin: true,
        module: 'user_management',
    },
];

export function navItems(user: NavUser, modules: readonly string[]): NavItem[] {
    const visible = (r: Rule) =>
        (!r.roles || r.roles.includes(user.role)) &&
        (!r.admin || user.is_admin) &&
        (!r.module || modules.includes(r.module));
    const pick = (rules: Rule[]): NavItem[] =>
        rules.filter(visible).map(({ key, label }) => ({ key, label }));

    const items = pick(MAIN);
    const admin = pick(ADMIN);
    if (admin.length) items.push({ key: '/admin', label: 'Admin', children: admin });
    return items;
}

/** Menu key for a pathname: the longest nav key that prefixes it. */
export function selectedKey(items: NavItem[], pathname: string): string | undefined {
    const keys = items.flatMap((i) => [i.key, ...(i.children ?? []).map((c) => c.key)]);
    return keys
        .filter((k) => pathname === k || (k !== '/' && pathname.startsWith(`${k}/`)))
        .sort((a, b) => b.length - a.length)[0];
}
