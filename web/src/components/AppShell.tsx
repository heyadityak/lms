'use client';

import {
    BellOutlined,
    CalendarOutlined,
    CheckSquareOutlined,
    DashboardOutlined,
    FileTextOutlined,
    LogoutOutlined,
    SettingOutlined,
    UserOutlined,
} from '@ant-design/icons';
import { Avatar, Button, Dropdown, Layout, Menu, Space, Typography } from 'antd';
import type { MenuProps } from 'antd';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useRef, type ReactNode } from 'react';
import { navItems, selectedKey, type NavItem, type NavUser } from './nav';

const ICONS: Record<string, ReactNode> = {
    '/': <DashboardOutlined />,
    '/leave-requests': <FileTextOutlined />,
    '/approvals': <CheckSquareOutlined />,
    '/holidays': <CalendarOutlined />,
    '/admin': <SettingOutlined />,
};

function toMenu(items: NavItem[]): MenuProps['items'] {
    return items.map((item) =>
        item.children
            ? {
                  key: item.key,
                  icon: ICONS[item.key],
                  label: item.label,
                  children: toMenu(item.children),
              }
            : {
                  key: item.key,
                  icon: ICONS[item.key],
                  label: <Link href={item.key}>{item.label}</Link>,
              },
    );
}

export interface AppShellProps {
    user: NavUser & { name: string };
    brand: { name: string; logo_url: string; modules: readonly string[] };
    children: ReactNode;
}

/** Layout per Figma Dashboard (484:2): Sider nav + Header (logo, notifications, user menu). */
export function AppShell({ user, brand, children }: AppShellProps) {
    const pathname = usePathname();
    const logoutForm = useRef<HTMLFormElement>(null);
    const items = navItems(user, brand.modules);
    const current = selectedKey(items, pathname);

    return (
        <Layout hasSider style={{ minHeight: '100vh' }}>
            <Layout.Sider breakpoint="lg" collapsible theme="light" aria-label="Main navigation">
                <Space style={{ padding: 16 }}>
                    {/* eslint-disable-next-line @next/next/no-img-element -- runtime brand asset from Laravel */}
                    <img src={brand.logo_url} alt={brand.name} height={32} />
                </Space>
                <Menu
                    mode="inline"
                    items={toMenu(items)}
                    selectedKeys={current ? [current] : []}
                    defaultOpenKeys={current?.startsWith('/admin') ? ['/admin'] : []}
                />
            </Layout.Sider>
            <Layout>
                <Layout.Header
                    style={{
                        background: 'var(--ant-color-bg-container)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        paddingInline: 24,
                    }}
                >
                    <Typography.Text strong>{brand.name}</Typography.Text>
                    <Space size="medium">
                        {/* shortcut: notifications are a placeholder until an endpoint backs them (UI_GUIDELINES §3). */}
                        <Button
                            type="text"
                            icon={<BellOutlined />}
                            aria-label="Notifications"
                            disabled
                        />
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
                            <Button type="text" aria-label={`User menu for ${user.name}`}>
                                <Space>
                                    <Avatar size="small" icon={<UserOutlined />} />
                                    {user.name}
                                </Space>
                            </Button>
                        </Dropdown>
                    </Space>
                    <form ref={logoutForm} method="post" action="/bff/logout" hidden />
                </Layout.Header>
                <Layout.Content style={{ margin: 24 }}>{children}</Layout.Content>
            </Layout>
        </Layout>
    );
}
