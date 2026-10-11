<?php

declare(strict_types=1);

use App\Shared\Support\Correlation\CorrelationId;

return [

    /*
    |--------------------------------------------------------------------------
    | Correlation_Identifier
    |--------------------------------------------------------------------------
    |
    | The header the Delivery_API accepts and echoes (Requirements 27.4, 27.5).
    | A request without it receives a generated ULID.
    |
    */

    'correlation' => [
        'header' => CorrelationId::HEADER,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-origin access (Requirement 28.4)
    |--------------------------------------------------------------------------
    |
    | Cross-origin access is granted exactly to the configured Web_Client
    | origins. `WEB_CLIENT_ORIGINS` is a comma-separated list of absolute
    | origins; an entry may use "*" as a wildcard segment
    | (e.g. https://*.waselna.example) but never "*" alone.
    |
    */

    'cors' => [
        'allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WEB_CLIENT_ORIGINS', 'http://localhost:5173')),
        ), static fn (string $origin): bool => $origin !== '')),

        'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

        'allowed_headers' => [
            'Accept',
            'Authorization',
            'Content-Type',
            'Idempotency-Key',
            CorrelationId::HEADER,
        ],

        'exposed_headers' => [
            CorrelationId::HEADER,
            'Retry-After',
        ],

        'max_age' => 600,

        'supports_credentials' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers (Requirement 28.9)
    |--------------------------------------------------------------------------
    |
    | Set on every Web_Client-facing response. The Delivery_API serves JSON
    | only, so the content security policy denies everything by default.
    |
    */

    'security_headers' => [
        'Strict-Transport-Security' => env(
            'SECURITY_HSTS',
            'max-age=31536000; includeSubDomains',
        ),
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => env(
            'SECURITY_CSP',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-minute rate limits (Requirement 28.3)
    |--------------------------------------------------------------------------
    |
    | 60 requests per minute per authenticated Platform user per endpoint group,
    | 20 per minute per unauthenticated client on the `auth` endpoint group.
    | Counters live in the Cache_Store (Redis in every deployed environment) as
    | `throttle:{scope}:{subject}`; `store` defaults to the default cache store.
    |
    | An unauthenticated client on any other endpoint group is subject to the
    | same 60-per-minute allowance, keyed by client address instead of by user
    | identifier, so a public read is not held to the sign-in allowance.
    |
    | The failed sign-in attempt limit of Requirement 1.7 is a separate counter
    | owned by the Auth module and is unaffected by these limits.
    |
    */

    'throttle' => [
        'store' => env('THROTTLE_CACHE_STORE'),

        'window_seconds' => 60,

        'limits' => [
            'authenticated' => 60,
            'unauthenticated' => 60,
            'unauthenticated_auth_endpoints' => 20,
        ],

        'auth_scope' => 'auth',
    ],

];
