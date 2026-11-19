import { AntdRegistry } from '@ant-design/nextjs-registry';
import { App, ConfigProvider } from 'antd';
import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { themeFromBranding } from '@/theme/brand';
import { getBranding } from '@/theme/branding';

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
        <html lang="en">
            <body style={{ margin: 0 }}>
                <AntdRegistry>
                    <ConfigProvider theme={themeFromBranding(brand)}>
                        <App>{children}</App>
                    </ConfigProvider>
                </AntdRegistry>
            </body>
        </html>
    );
}
