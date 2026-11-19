<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // socialiteproviders/keycloak (web login). Values come from config/keycloak.php's env vars.
    'keycloak' => [
        'client_id' => env('KEYCLOAK_CLIENT_ID', 'lms-web'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI', 'http://localhost:8080/auth/callback'),
        'base_url' => env('KEYCLOAK_BASE_URL', 'http://keycloak:8080'),
        'public_url' => env('KEYCLOAK_PUBLIC_URL', 'http://localhost:8081'),
        'realms' => env('KEYCLOAK_REALM', 'lms'),
    ],

];
