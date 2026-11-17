<?php

use App\Support\CorsAllowedOrigins;

/**
 * CORS Configuration — BINAA2
 *
 * Supports Laravel Sanctum SPA Cookie-based Authentication (C-002 §26).
 * `supports_credentials` must be true for SPA cookie auth (session + XSRF-TOKEN).
 * Allowed origins are FRONTEND_URL plus any explicit CORS_ALLOWED_ORIGINS.
 *
 * Security: credentials are only sent to explicitly listed origins — never wildcard.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL is the primary web origin. CORS_ALLOWED_ORIGINS is an
    // optional comma-separated list of additional exact origins. Production
    // does not fall back to localhost and rejects a wildcard.
    'allowed_origins' => CorsAllowedOrigins::resolve(
        env('APP_ENV', 'production'),
        env('FRONTEND_URL'),
        env('CORS_ALLOWED_ORIGINS', '')
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Allow SPA to read resend wait hints on 429 (display only; server remains authoritative).
    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,

];
