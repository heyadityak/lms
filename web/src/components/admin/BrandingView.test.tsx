// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { App } from 'antd';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Schemas } from '@/lib/api/client';
import { revalidateBranding } from '@/lib/revalidate-branding';
import { BrandingForm } from './BrandingView';

vi.mock('@/lib/revalidate-branding', () => ({
    revalidateBranding: vi.fn(async () => undefined),
}));

function brand(primary: string): Schemas['Branding'] {
    return {
        name: 'Leave Manager',
        primary_color: primary,
        accent_color: '#047857',
        primary_contrast: '#FFFFFF',
        logo_url: '/branding/logo',
        favicon_url: '/branding/favicon',
        overridden: [],
    };
}

function renderForm(primary: string) {
    return render(
        <App>
            <BrandingForm initial={brand(primary)} onUpdated={() => undefined} />
        </App>,
    );
}

describe('BrandingForm contrast', () => {
    it('warns when white text on the primary colour fails WCAG', () => {
        renderForm('#FFFF00');
        expect(screen.getByText(/fails WCAG contrast with white text/)).toBeTruthy();
    });

    it('shows no contrast warning when white text on the primary colour passes', () => {
        renderForm('#3A5FBE');
        expect(screen.queryByText(/fails WCAG contrast with white text/)).toBeNull();
    });
});

describe('BrandingForm save', () => {
    beforeEach(() => {
        vi.mocked(revalidateBranding).mockClear();
        vi.stubGlobal(
            'fetch',
            async () =>
                new Response(
                    JSON.stringify({
                        data: brand('#3A5FBE'),
                        meta: {},
                        links: {},
                    }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } },
                ),
        );
    });

    it('refreshes the branding cache after a successful save', async () => {
        renderForm('#3A5FBE');
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(revalidateBranding).toHaveBeenCalledOnce());
    });
});
