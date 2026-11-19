import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
    output: 'standalone',
    poweredByHeader: false,
    serverExternalPackages: ['better-auth'],
    // The one-off migrate job (D-45) runs from the standalone image, so ship the script and what it imports.
    outputFileTracingIncludes: {
        '/bff/healthz': ['./scripts/auth-migrate.mjs', './src/auth/options.mjs'],
    },
};

export default nextConfig;
