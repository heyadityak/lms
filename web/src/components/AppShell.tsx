'use client';

import {
    AppstoreOutlined,
    BellOutlined,
    CalendarOutlined,
    CheckSquareOutlined,
    DownOutlined,
    LogoutOutlined,
    MenuOutlined,
    ScheduleOutlined,
    SettingOutlined,
} from '@ant-design/icons';
import { Badge, Button, Drawer, Dropdown, Grid, Layout, Space, Typography, theme } from 'antd';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { usePendingApprovalCount } from '@/lib/queries';
import { FIGMA, activeNavColor } from '@/theme/brand';
import { navItems, selectedKey, type NavItem } from './nav';
import { AppProviders, type ShellUser } from './providers';
import { InitialsAvatar, isApprover } from './ui';

const ICONS: Record<string, ReactNode> = {
    '/': <AppstoreOutlined />,
    '/leave-requests': <ScheduleOutlined />,
    '/approvals': <CheckSquareOutlined />,
    '/holidays': <CalendarOutlined />,
    '/admin': <SettingOutlined />,
};

export interface AppShellProps {
    user: ShellUser;
    brand: {
        name: string;
        logo_url: string;
        primary_color: string;
        primary_contrast: string;
        modules: readonly string[];
    };
    children: ReactNode;
}

/** Figma shell (484:2): 80 px icon-over-label Sider, 64 px Header; Drawer nav below `md`. */
export function AppShell({ user, brand, children }: AppShellProps) {
    return (
        <AppProviders user={user} modules={brand.modules}>
            <Shell user={user} brand={brand}>
                {children}
            </Shell>
        </AppProviders>
    );
}

function navFace(current: boolean, color: string, activeBg: string): CSSProperties {
    return {
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 6,
        width: '100%',
        height: 84,
        padding: 0,
        color,
        background: current ? activeBg : 'transparent',
        fontSize: 12,
        fontWeight: current ? 700 : 400,
        fontFamily: 'inherit',
        textDecoration: 'none',
        border: 0,
        cursor: 'pointer',
    };
}

function NavLinks({
    items,
    active,
    current,
    color,
    activeBg,
    onNavigate,
}: {
    items: NavItem[];
    active?: string;
    current?: string;
    color: string;
    activeBg: string;
    onNavigate?: () => void;
}) {
    return (
        <nav aria-label="Main navigation" className="lms-nav">
            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                {items.map((item) => {
                    const sectionCurrent = item.key === active;
                    return (
                        <li key={item.key}>
                            {item.children?.length ? (
                                <Dropdown
                                    trigger={['click']}
                                    placement="rightTop"
                                    menu={{
                                        selectedKeys: current ? [current] : [],
                                        items: item.children.map((child) => ({
                                            key: child.key,
                                            label: (
                                                <Link
                                                    href={child.key}
                                                    onClick={onNavigate}
                                                    aria-current={
                                                        child.key === current ? 'page' : undefined
                                                    }
                                                >
                                                    {child.label}
                                                </Link>
                                            ),
                                        })),
                                    }}
                                >
                                    <button
                                        type="button"
                                        aria-haspopup="menu"
                                        style={navFace(sectionCurrent, color, activeBg)}
                                    >
                                        <span style={{ fontSize: 24 }} aria-hidden>
                                            {ICONS[item.key]}
                                        </span>
                                        {item.label}
                                    </button>
                                </Dropdown>
                            ) : (
                                <Link
                                    href={item.key}
                                    onClick={onNavigate}
                                    aria-current={sectionCurrent ? 'page' : undefined}
                                    style={navFace(sectionCurrent, color, activeBg)}
                                >
                                    <span style={{ fontSize: 24 }} aria-hidden>
                                        {ICONS[item.key]}
                                    </span>
                                    {item.label}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/** Sibling admin routes, visible once an admin page is open. */
function AdminSubnav({ items, current }: { items: NavItem[]; current?: string }) {
    return (
        <nav aria-label="Admin" style={{ marginBottom: 8 }}>
            <Space wrap size="middle">
                {items.map((item) => {
                    const on = item.key === current;
                    return (
                        <Link
                            key={item.key}
                            href={item.key}
                            aria-current={on ? 'page' : undefined}
                            style={{
                                fontWeight: on ? 700 : 400,
                                textDecoration: on ? 'underline' : 'none',
                            }}
                        >
                            {item.label}
                        </Link>
                    );
                })}
            </Space>
        </nav>
    );
}

function Shell({
    user,
    brand,
    children,
}: Omit<AppShellProps, 'children'> & { children: ReactNode }) {
    const screens = Grid.useBreakpoint();
    const mobile = screens.md === false; // undefined during SSR → desktop layout
    const [drawerOpen, setDrawerOpen] = useState(false);
    const logoutForm = useRef<HTMLFormElement>(null);
    const pathname = usePathname();
    const { token } = theme.useToken();
    const approver = isApprover(user.role);
    const pending = usePendingApprovalCount(approver);

    const items = navItems(user, brand.modules);
    const key = selectedKey(items, pathname);
    const active = key?.startsWith('/admin') ? '/admin' : key;
    const admin = items.find((item) => item.key === '/admin');
    const nav = (onNavigate?: () => void) => (
        <NavLinks
            items={items}
            active={active}
            current={key}
            color={brand.primary_contrast}
            activeBg={activeNavColor(token.colorPrimary)}
            onNavigate={onNavigate}
        />
    );
    const logo = (
        <div style={{ display: 'flex', justifyContent: 'center', padding: '12px 0' }}>
            {/* eslint-disable-next-line @next/next/no-img-element -- runtime brand asset from Laravel */}
            <img
                src={brand.logo_url}
                alt={brand.name}
                width={40}
                height={40}
                style={{ borderRadius: '50%', background: '#FFFFFF', objectFit: 'contain' }}
            />
        </div>
    );

    return (
        <Layout hasSider={!mobile} style={{ minHeight: '100vh' }}>
            {!mobile && (
                <Layout.Sider
                    width={80}
                    style={{ position: 'sticky', top: 0, height: '100vh', overflowY: 'auto' }}
                >
                    {logo}
                    {nav()}
                </Layout.Sider>
            )}
            <Drawer
                open={mobile && drawerOpen}
                onClose={() => setDrawerOpen(false)}
                placement="left"
                size={120}
                title="Menu"
                styles={{ body: { padding: 0, background: token.colorPrimary } }}
            >
                {logo}
                {nav(() => setDrawerOpen(false))}
            </Drawer>
            <Layout>
                <Layout.Header
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        boxShadow: FIGMA.headerShadow,
                        position: 'sticky',
                        top: 0,
                        zIndex: 10,
                    }}
                >
                    <Space>
                        {mobile && (
                            <Button
                                type="text"
                                icon={<MenuOutlined />}
                                aria-label="Open navigation"
                                onClick={() => setDrawerOpen(true)}
                            />
                        )}
                        <Typography.Text strong style={{ color: FIGMA.heading, fontSize: 16 }}>
                            {brand.name}
                        </Typography.Text>
                    </Space>
                    <Space size="large">
                        {approver && (
                            <Link
                                href="/approvals"
                                aria-label={`${pending.data ?? 0} pending approvals`}
                                style={{ color: token.colorPrimary, fontSize: 22, display: 'flex' }}
                            >
                                <Badge count={pending.data ?? 0} size="small">
                                    <BellOutlined
                                        style={{ fontSize: 22, color: token.colorPrimary }}
                                    />
                                </Badge>
                            </Link>
                        )}
                        <Dropdown
                            trigger={['click']}
                            menu={{
                                items: [
                                    { key: 'logout', icon: <LogoutOutlined />, label: 'Sign out' },
                                ],
                                onClick: ({ key }) => {
                                    if (key === 'logout') logoutForm.current?.requestSubmit();
                                },
                            }}
                        >
                            <Button
                                type="text"
                                aria-label={`User menu for ${user.name}`}
                                style={{ height: 48 }}
                            >
                                <Space>
                                    <InitialsAvatar name={user.name} />
                                    {!mobile && (
                                        <span style={{ color: FIGMA.heading }}>{user.name}</span>
                                    )}
                                    <DownOutlined style={{ color: FIGMA.heading }} />
                                </Space>
                            </Button>
                        </Dropdown>
                    </Space>
                    <form ref={logoutForm} method="post" action="/bff/logout" hidden />
                </Layout.Header>
                <Layout.Content
                    style={{
                        padding: mobile ? 16 : '30px 33px',
                        width: '100%',
                        maxWidth: 1440,
                        marginInline: 'auto',
                    }}
                >
                    {pathname.startsWith('/admin') && admin?.children && (
                        <AdminSubnav items={admin.children} current={key} />
                    )}
                    <main>{children}</main>
                </Layout.Content>
            </Layout>
        </Layout>
    );
}
