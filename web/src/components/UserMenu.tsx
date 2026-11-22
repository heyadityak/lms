'use client';

import { DeleteOutlined, DownOutlined, UserOutlined } from '@ant-design/icons';
import { App, Avatar, Button, Dropdown, Space } from 'antd';
import Link from 'next/link';
import { useState } from 'react';
import { browserApi as api, unwrapEmpty } from '@/lib/api/client';
import { FIGMA } from '@/theme/brand';
import { InitialsAvatar, initials } from './ui';

const AVATAR_BYTES = 1024 * 1024;

function avatarError(file: File): string | null {
    const ext = file.name.split('.').pop()?.toLowerCase();
    const typeOk =
        file.type === 'image/png' ||
        file.type === 'image/jpeg' ||
        file.type === 'image/webp' ||
        ext === 'png' ||
        ext === 'jpg' ||
        ext === 'jpeg' ||
        ext === 'webp';
    if (!typeOk) return 'Upload a PNG, JPEG or WebP';
    if (file.size > AVATAR_BYTES) return 'Photo must be 1 MB or smaller';
    return null;
}

/** Header menu: photo via `/me/avatar`, otherwise initials, then profile and sign out. */
export function UserMenu({
    id,
    name,
    avatarUrl,
    mobile,
}: {
    id?: string;
    name: string;
    avatarUrl?: string | null;
    mobile: boolean;
}) {
    const { message } = App.useApp();
    const [removed, setRemoved] = useState(false);
    const [version, setVersion] = useState(0);
    const [broken, setBroken] = useState(false);
    const show = !!id && !removed && !broken && (version > 0 || !!avatarUrl);
    const src = show ? `/bff/api/users/${id}/avatar${version ? `?v=${version}` : ''}` : null;

    const upload = async (file: File) => {
        const invalid = avatarError(file);
        if (invalid) {
            message.error(invalid);
            return;
        }
        const body = new FormData();
        body.set('file', file);
        try {
            await unwrapEmpty(
                api.POST('/me/avatar', { body: body as unknown as { file: string } }),
            );
            message.success('Photo updated');
            setBroken(false);
            setRemoved(false);
            setVersion((current) => current + 1);
        } catch (e) {
            message.error(e instanceof Error ? e.message : 'Could not update the photo');
        }
    };

    const remove = async () => {
        try {
            await unwrapEmpty(api.DELETE('/me/avatar'));
            message.success('Photo removed');
            setRemoved(true);
        } catch (e) {
            message.error(e instanceof Error ? e.message : 'Could not remove the photo');
        }
    };

    return (
        <Dropdown
            trigger={['click']}
            menu={{
                items: [
                    {
                        key: 'upload',
                        label: (
                            <label style={{ cursor: 'pointer', display: 'block' }}>
                                Upload photo
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp"
                                    aria-label="Upload photo"
                                    hidden
                                    onChange={(event) => {
                                        const file = event.target.files?.[0];
                                        event.target.value = '';
                                        if (file) void upload(file);
                                    }}
                                />
                            </label>
                        ),
                    },
                    ...(src
                        ? [{ key: 'remove', icon: <DeleteOutlined />, label: 'Remove photo' }]
                        : []),
                    {
                        key: 'profile',
                        icon: <UserOutlined />,
                        label: <Link href="/profile">Profile</Link>,
                    },
                    { key: 'logout', label: 'Sign out' },
                ],
                onClick: ({ key }) => {
                    if (key === 'logout') {
                        document
                            .querySelector<HTMLFormElement>('form[action="/bff/logout"]')
                            ?.requestSubmit();
                    }
                    if (key === 'remove') void remove();
                },
            }}
        >
            <Button
                type="text"
                aria-label={`${initials(name)} ${name}, User menu for ${name}`}
                style={{ height: 48 }}
            >
                <Space>
                    {src ? (
                        <Avatar
                            src={src}
                            alt=""
                            size={32}
                            onError={() => {
                                setBroken(true);
                                return true;
                            }}
                        />
                    ) : (
                        <InitialsAvatar name={name} />
                    )}
                    {!mobile && <span style={{ color: FIGMA.heading }}>{` ${name}`}</span>}
                    <DownOutlined style={{ color: FIGMA.heading }} />
                </Space>
            </Button>
        </Dropdown>
    );
}
