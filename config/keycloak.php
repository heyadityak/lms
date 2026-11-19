<?php

declare(strict_types=1);

// Keycloak is the identity provider and role authority (see .logs/decisions.md).
return [
    // Back-channel URL the server uses (token, userinfo, JWKS). Inside Docker: http://keycloak:8080
    'base_url' => rtrim((string) env('KEYCLOAK_BASE_URL', 'http://keycloak:8080'), '/'),
    // Browser-facing URL; also the token issuer host (iss = {public_url}/realms/{realm}).
    'public_url' => rtrim((string) env('KEYCLOAK_PUBLIC_URL', 'http://localhost:8081'), '/'),
    'realm' => env('KEYCLOAK_REALM', 'lms'),
    // First-party BFF client (Next.js, D-43): tokens with this `azp` pass `module:api`. Its secret lives only in `web`.
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'lms-web'),
    // Service account (client credentials) for the Admin REST API: manage/view/query-users only.
    'admin_client_id' => env('KEYCLOAK_ADMIN_CLIENT_ID', 'lms-admin'),
    'admin_client_secret' => env('KEYCLOAK_ADMIN_CLIENT_SECRET'),

    // Bearer tokens are accepted when `aud` or `azp` contains one of these.
    'allowed_audiences' => array_values(array_filter(array_map('trim', explode(',', (string) env('KEYCLOAK_ALLOWED_AUDIENCES', 'lms-web,lms-api-cli'))))),
    'leeway' => (int) env('KEYCLOAK_JWT_LEEWAY', 30),
    'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 3600),

];
