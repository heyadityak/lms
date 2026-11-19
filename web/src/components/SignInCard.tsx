'use client';

import { LoginOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Flex, Typography } from 'antd';
import { useState } from 'react';

/** Branded sign-in card; starts the Keycloak flow (Better Auth POST /auth/sign-in/social, D-45 (b)). */
export function SignInCard({ name, logoUrl }: { name: string; logoUrl: string }) {
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    async function signIn() {
        setBusy(true);
        setFailed(false);
        try {
            const res = await fetch('/auth/sign-in/social', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ provider: 'keycloak', callbackURL: '/' }),
            });
            const body = (await res.json()) as { url?: string };
            if (!res.ok || !body.url) throw new Error('sign-in failed');
            window.location.assign(body.url);
        } catch {
            setFailed(true);
            setBusy(false);
        }
    }

    return (
        <Card style={{ width: 360 }}>
            <Flex vertical align="center" gap="medium">
                {/* eslint-disable-next-line @next/next/no-img-element -- runtime brand asset from Laravel */}
                <img src={logoUrl} alt="" height={48} />
                <Typography.Title level={1} style={{ fontSize: 24, margin: 0 }}>
                    {name}
                </Typography.Title>
                {failed && (
                    <Alert
                        type="error"
                        showIcon
                        title="Sign-in is unavailable. Try again shortly."
                    />
                )}
                <Button
                    type="primary"
                    size="large"
                    block
                    icon={<LoginOutlined />}
                    loading={busy}
                    onClick={signIn}
                >
                    Sign in with SSO
                </Button>
            </Flex>
        </Card>
    );
}
