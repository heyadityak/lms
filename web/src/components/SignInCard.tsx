'use client';

import { Alert, Button, Form, Input, Typography } from 'antd';
import { useState } from 'react';

const REJECTED = 'Those credentials were not accepted.';
const UNAVAILABLE = 'Sign-in is unavailable. Try again shortly.';

/** Branded welcome. Submits username and password to POST /bff/login (same origin). */
export function SignInCard({
    name,
    logoUrl,
    primaryColor,
    primaryContrast,
}: {
    name: string;
    logoUrl: string;
    primaryColor: string;
    primaryContrast: string;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<'rejected' | 'unavailable' | null>(null);

    async function signIn(values: { username: string; password: string }) {
        setBusy(true);
        setError(null);
        try {
            const res = await fetch('/bff/login', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username: values.username,
                    password: values.password,
                }),
            });
            if (res.status === 401) {
                setError('rejected');
                return;
            }
            if (!res.ok) {
                setError('unavailable');
                return;
            }
            // Full navigation so the new HttpOnly session cookie is on the document request.
            // eslint-disable-next-line @next/next/no-location-assign-relative-destination
            window.location.assign('/');
        } catch {
            setError('unavailable');
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="lms-welcome" aria-labelledby="welcome-title">
            <div
                className="lms-welcome-brand"
                style={{ background: primaryColor, color: primaryContrast }}
            >
                <div className="lms-welcome-logo">
                    {/* eslint-disable-next-line @next/next/no-img-element -- runtime brand asset from Laravel */}
                    <img src={logoUrl} alt="" width={48} height={48} />
                </div>
                <p className="lms-welcome-kicker">Welcome</p>
                <h1 id="welcome-title">{name}</h1>
                <p className="lms-welcome-lead">
                    Request leave, approve time off, and check your balances.
                </p>
            </div>
            <div className="lms-welcome-panel">
                <Typography.Title level={2} style={{ marginTop: 0, fontSize: 22 }}>
                    Sign in
                </Typography.Title>
                <Typography.Paragraph style={{ marginBottom: 24 }}>
                    Enter your username and password.
                </Typography.Paragraph>
                {error && (
                    <Alert
                        type="error"
                        showIcon
                        style={{ marginBottom: 16 }}
                        title={error === 'rejected' ? REJECTED : UNAVAILABLE}
                    />
                )}
                <Form
                    layout="vertical"
                    requiredMark
                    onFinish={(values: { username: string; password: string }) => {
                        void signIn(values);
                    }}
                    autoComplete="on"
                >
                    <Form.Item
                        name="username"
                        label="Username"
                        rules={[
                            { required: true, whitespace: true, message: 'Username is required' },
                        ]}
                    >
                        <Input autoComplete="username" autoFocus size="large" />
                    </Form.Item>
                    <Form.Item
                        name="password"
                        label="Password"
                        rules={[{ required: true, message: 'Password is required' }]}
                    >
                        <Input.Password autoComplete="current-password" size="large" />
                    </Form.Item>
                    <Button type="primary" htmlType="submit" size="large" block loading={busy}>
                        Sign in
                    </Button>
                </Form>
            </div>
        </section>
    );
}
