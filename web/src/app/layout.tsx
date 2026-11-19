import { AntdRegistry } from '@ant-design/nextjs-registry';
import { App, ConfigProvider } from 'antd';
import type { Metadata } from 'next';
import { Open_Sans } from 'next/font/google';
import type { ReactNode } from 'react';
import { FIGMA, themeFromBranding } from '@/theme/brand';
import { getBranding } from '@/theme/branding';
import './globals.css';

// Downloaded at build time and served from /_next (no runtime Google requests).
const openSans = Open_Sans({
    subsets: ['latin'],
    weight: ['400', '600', '700'],
    variable: '--font-open-sans',
    display: 'swap',
});

// Branding is runtime data (12-factor): never prerender it at build time.
export const dynamic = 'force-dynamic';

export async function generateMetadata(): Promise<Metadata> {
    const brand = await getBranding();
    return {
        title: { default: brand.name, template: `%s · ${brand.name}` },
        icons: { icon: brand.favicon_url },
    };
}

export default async function RootLayout({ children }: { children: ReactNode }) {
    const brand = await getBranding();
    return (
        <html lang="en" className={openSans.variable}>
            <body style={{ margin: 0 }}>
                <AntdRegistry>
                    <ConfigProvider
                        theme={themeFromBranding(brand)}
                        card={{ variant: 'borderless', style: { boxShadow: FIGMA.cardShadow } }}
                    >
                        <App>{children}</App>
                    </ConfigProvider>
                </AntdRegistry>
            </body>
        </html>
    );
}
