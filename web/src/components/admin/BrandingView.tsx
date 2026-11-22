'use client';

import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    App,
    Button,
    ColorPicker,
    Form,
    Input,
    Popconfirm,
    Skeleton,
    Space,
    Typography,
    Upload,
} from 'antd';
import type { UploadProps } from 'antd';
import type { ComponentProps } from 'react';
import { useState } from 'react';
import { browserApi as api, unwrap, type Schemas } from '@/lib/api/client';
import { assetFileError, type BrandAsset } from '@/lib/brand-asset';
import { applyFieldErrors, unmappedError } from '@/lib/fields';
import { revalidateBranding } from '@/lib/revalidate-branding';
import { contrastWarning, normalizeHex } from '@/theme/contrast';
import { PageTitle, ProblemAlert } from '../ui';

type Branding = Schemas['Branding'];

interface Values {
    name: string;
    primary_color: string;
    accent_color: string;
}

const HEX_RULE = {
    validator: async (_: unknown, value: string | undefined) => {
        if (!value || !normalizeHex(value)) throw new Error('Use a #RRGGBB colour');
    },
};

function brandKey(brand: Branding): string {
    return [
        brand.name,
        brand.primary_color,
        brand.accent_color,
        brand.logo_url,
        brand.favicon_url,
        brand.overridden.join(','),
    ].join('|');
}

/** Brand name, colours, logo, and favicon (UC-B1–B4, UC-B6). Admin only. */
export function BrandingView() {
    const queryClient = useQueryClient();
    const branding = useQuery({
        queryKey: ['branding-settings'],
        queryFn: async () => (await unwrap(api.GET('/settings/branding'))).data,
    });

    if (branding.isPending) {
        return (
            <>
                <PageTitle>Branding</PageTitle>
                <Skeleton active />
            </>
        );
    }
    if (branding.isError || !branding.data) {
        return (
            <>
                <PageTitle>Branding</PageTitle>
                <ProblemAlert error={branding.error} onRetry={() => branding.refetch()} />
            </>
        );
    }

    return (
        <BrandingForm
            key={brandKey(branding.data)}
            initial={branding.data}
            onUpdated={(next) => queryClient.setQueryData(['branding-settings'], next)}
        />
    );
}

export function BrandingForm({
    initial,
    onUpdated,
}: {
    initial: Branding;
    onUpdated: (next: Branding) => void;
}) {
    const { message } = App.useApp();
    const [form] = Form.useForm<Values>();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<unknown>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const watchedPrimary = Form.useWatch('primary_color', form);
    const warning = contrastWarning(watchedPrimary ?? initial.primary_color);

    const publish = async (next: Branding, success: string) => {
        // Saved on the API. Cache refresh is best-effort; the tag also expires on its TTL.
        await revalidateBranding().catch(() => undefined);
        message.success(success);
        onUpdated(next);
    };

    const save = async (values: Values) => {
        const primary = normalizeHex(values.primary_color);
        const accent = normalizeHex(values.accent_color);
        if (!primary || !accent) return;
        const body: Schemas['BrandingUpdate'] = {
            name: values.name.trim(),
            primary_color: primary,
            accent_color: accent,
        };
        setSaving(true);
        setError(null);
        try {
            const next = await unwrap(api.PATCH('/settings/branding', { body }));
            await publish(next.data, 'Branding saved');
        } catch (e) {
            setError(unmappedError(e, applyFieldErrors(form, e)));
        } finally {
            setSaving(false);
        }
    };

    const reset = async () => {
        setSaving(true);
        setError(null);
        setFileError(null);
        try {
            const next = await unwrap(api.DELETE('/settings/branding'));
            await publish(next.data, 'Branding reset to deployment defaults');
        } catch (e) {
            setError(e);
        } finally {
            setSaving(false);
        }
    };

    const upload =
        (asset: BrandAsset): UploadProps['customRequest'] =>
        async (options) => {
            const file = options.file as File;
            const invalid = assetFileError(asset, file);
            if (invalid) {
                setFileError(invalid);
                options.onError?.(new Error(invalid));
                return;
            }
            setFileError(null);
            setError(null);
            setSaving(true);
            try {
                const body = new FormData();
                body.set('file', file);
                const next = await unwrap(
                    api.PUT('/settings/branding/assets/{asset}', {
                        params: { path: { asset } },
                        // Runtime body is FormData; openapi-fetch leaves that intact (not JSON).
                        body: body as unknown as { file: string },
                    }),
                );
                options.onSuccess?.({});
                await publish(next.data, asset === 'logo' ? 'Logo updated' : 'Favicon updated');
            } catch (e) {
                options.onError?.(e instanceof Error ? e : new Error('Upload failed'));
                setError(e);
            } finally {
                setSaving(false);
            }
        };

    const removeAsset = async (asset: BrandAsset) => {
        setError(null);
        setFileError(null);
        setSaving(true);
        try {
            const next = await unwrap(
                api.DELETE('/settings/branding/assets/{asset}', {
                    params: { path: { asset } },
                }),
            );
            await publish(
                next.data,
                asset === 'logo' ? 'Logo reset to the default' : 'Favicon reset to the default',
            );
        } catch (e) {
            setError(e);
        } finally {
            setSaving(false);
        }
    };

    const beforeUpload =
        (asset: BrandAsset): UploadProps['beforeUpload'] =>
        (file) => {
            const invalid = assetFileError(asset, file);
            if (invalid) {
                setFileError(invalid);
                return Upload.LIST_IGNORE;
            }
            setFileError(null);
            return true;
        };

    return (
        <>
            <PageTitle>Branding</PageTitle>
            <Typography.Paragraph>
                {initial.overridden.length
                    ? `Overrides: ${initial.overridden.join(', ')}.`
                    : 'Using deployment defaults.'}{' '}
                Text on primary buttons uses {initial.primary_contrast}.
            </Typography.Paragraph>
            {warning ? <Alert type="warning" showIcon title={warning} /> : null}
            {fileError ? <Alert type="error" showIcon title={fileError} /> : null}
            {error ? <ProblemAlert error={error} /> : null}
            <Form<Values>
                form={form}
                layout="vertical"
                style={{ maxWidth: 560 }}
                initialValues={{
                    name: initial.name,
                    primary_color: initial.primary_color,
                    accent_color: initial.accent_color,
                }}
                onFinish={(values) => void save(values)}
            >
                <Form.Item
                    name="name"
                    label="Name"
                    rules={[
                        { required: true, whitespace: true, message: 'Name is required' },
                        { max: 60, message: 'At most 60 characters' },
                    ]}
                >
                    <Input maxLength={60} />
                </Form.Item>
                <Form.Item
                    name="primary_color"
                    label="Primary colour"
                    extra="Hex only. White text on this colour should meet WCAG 2.2 AA (4.5:1). A failing colour can still be saved."
                    rules={[HEX_RULE]}
                >
                    <HexColourPicker />
                </Form.Item>
                <Form.Item
                    name="accent_color"
                    label="Accent colour"
                    extra="Used for success and approved states."
                    rules={[HEX_RULE]}
                >
                    <HexColourPicker />
                </Form.Item>
                <Form.Item label="Logo" extra="PNG, JPEG, or WebP. 1 MB max. SVG is not allowed.">
                    <AssetControls
                        src={initial.logo_url}
                        alt="Current logo"
                        accept="image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp"
                        uploadLabel="Upload logo"
                        removeLabel="Remove logo"
                        canRemove={initial.overridden.includes('brand.logo_path')}
                        beforeUpload={beforeUpload('logo')}
                        customRequest={upload('logo')}
                        onRemove={() => void removeAsset('logo')}
                        disabled={saving}
                    />
                </Form.Item>
                <Form.Item label="Favicon" extra="PNG or ICO. 256 KB max. SVG is not allowed.">
                    <AssetControls
                        src={initial.favicon_url}
                        alt="Current favicon"
                        accept="image/png,image/x-icon,image/vnd.microsoft.icon,.png,.ico"
                        uploadLabel="Upload favicon"
                        removeLabel="Remove favicon"
                        canRemove={initial.overridden.includes('brand.favicon_path')}
                        beforeUpload={beforeUpload('favicon')}
                        customRequest={upload('favicon')}
                        onRemove={() => void removeAsset('favicon')}
                        disabled={saving}
                    />
                </Form.Item>
                <Space wrap>
                    <Button type="primary" htmlType="submit" loading={saving}>
                        Save
                    </Button>
                    <Popconfirm
                        title="Reset branding to defaults?"
                        description="Name, colours, logo, and favicon return to the deployment defaults."
                        okText="Reset to defaults"
                        cancelText="Back"
                        onConfirm={() => void reset()}
                    >
                        <Button htmlType="button" disabled={saving}>
                            Reset to defaults
                        </Button>
                    </Popconfirm>
                </Space>
            </Form>
        </>
    );
}

function HexColourPicker({
    id,
    value,
    onChange,
}: {
    id?: string;
    value?: string;
    onChange?: (hex: string) => void;
}) {
    const picker: ComponentProps<typeof ColorPicker> = {
        format: 'hex',
        disabledAlpha: true,
        showText: true,
        value,
        onChange: (color) => {
            const hex = normalizeHex(color.toHexString());
            if (hex) onChange?.(hex);
        },
    };
    // Form.Item passes `id` for the label. ColorTrigger forwards it; the public props omit it.
    return <ColorPicker {...picker} {...(id ? { id } : {})} />;
}

function AssetControls({
    src,
    alt,
    accept,
    uploadLabel,
    removeLabel,
    canRemove,
    beforeUpload,
    customRequest,
    onRemove,
    disabled,
}: {
    src: string;
    alt: string;
    accept: string;
    uploadLabel: string;
    removeLabel: string;
    canRemove: boolean;
    beforeUpload: UploadProps['beforeUpload'];
    customRequest: UploadProps['customRequest'];
    onRemove: () => void;
    disabled: boolean;
}) {
    return (
        <Space wrap align="center">
            {/* eslint-disable-next-line @next/next/no-img-element -- runtime brand asset from Laravel */}
            <img
                src={src}
                alt={alt}
                width={48}
                height={48}
                style={{ objectFit: 'contain', background: '#FFFFFF' }}
            />
            <Upload
                accept={accept}
                maxCount={1}
                showUploadList={false}
                beforeUpload={beforeUpload}
                customRequest={customRequest}
            >
                <Button htmlType="button" disabled={disabled}>
                    {uploadLabel}
                </Button>
            </Upload>
            <Button htmlType="button" onClick={onRemove} disabled={disabled || !canRemove}>
                {removeLabel}
            </Button>
        </Space>
    );
}
