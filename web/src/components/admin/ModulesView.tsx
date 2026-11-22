'use client';

import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, App, Form, Skeleton, Switch, Typography } from 'antd';
import { useState } from 'react';
import { ApiError, browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { revalidateBranding } from '@/lib/revalidate-branding';
import { PageTitle, ProblemAlert } from '../ui';

type Module = Schemas['Module'];

function moduleStatus(mod: Module): string {
    if (!mod.licensed && !mod.available) return 'Locked. Not licensed and not available.';
    if (!mod.licensed) return 'Locked. Not licensed.';
    if (!mod.available) return 'Locked. Not available.';
    if (mod.locked) return 'Locked. This module cannot be enabled.';
    return mod.enabled ? 'Enabled' : 'Disabled';
}

/** Licensed module switches (UC-B5). Locked rows cannot be enabled. */
export function ModulesView() {
    const { message } = App.useApp();
    const queryClient = useQueryClient();
    const [pending, setPending] = useState<string | null>(null);
    const [error, setError] = useState<unknown>(null);
    const modules = useQuery({
        queryKey: ['modules'],
        queryFn: async () => (await unwrap(api.GET('/modules'))).data,
    });

    const toggle = async (mod: Module, enabled: boolean) => {
        if (mod.locked) return;
        setPending(mod.key);
        setError(null);
        try {
            await unwrap(
                api.PATCH('/modules/{moduleKey}', {
                    params: { path: { moduleKey: mod.key } },
                    body: { enabled },
                }),
            );
            await revalidateBranding().catch(() => undefined);
            message.success(`${mod.label} ${enabled ? 'enabled' : 'disabled'}`);
            await queryClient.invalidateQueries({ queryKey: ['modules'] });
        } catch (e) {
            setError(e);
        } finally {
            setPending(null);
        }
    };

    return (
        <>
            <PageTitle>Modules</PageTitle>
            <Typography.Paragraph>
                Only licensed modules can be turned on. Turning one off hides it in the menu.
                Disabling API blocks third-party clients only. This app keeps working.
            </Typography.Paragraph>
            {modules.isPending && <Skeleton active />}
            {modules.isError && (
                <ProblemAlert error={modules.error} onRetry={() => modules.refetch()} />
            )}
            <ModuleProblem error={error} />
            {(modules.data ?? []).map((mod) => (
                <Form key={mod.key} component="div" style={{ maxWidth: 560 }}>
                    <Form.Item
                        label={mod.label}
                        extra={moduleStatus(mod)}
                        style={{ marginBottom: 12 }}
                    >
                        <Switch
                            checked={mod.enabled}
                            disabled={mod.locked || pending === mod.key}
                            aria-label={mod.label}
                            onChange={(enabled) => void toggle(mod, enabled)}
                        />
                    </Form.Item>
                </Form>
            ))}
        </>
    );
}

function ModuleProblem({ error }: { error: unknown }) {
    if (!error) return null;
    if (error instanceof ApiError && error.problem.code === 'MODULE_NOT_LICENSED') {
        return (
            <Alert
                type="error"
                showIcon
                style={{ marginBottom: 16 }}
                title="Module is not licensed"
                description={
                    error.problem.detail ??
                    'This module is not licensed for this deployment, so it stays off.'
                }
            />
        );
    }
    return <ProblemAlert error={error} />;
}
