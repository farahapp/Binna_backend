<?php

/**
 * CORS Configuration — BINAA2
 *
 * Supports Laravel Sanctum SPA Cookie-based Authentication (C-002 §26).
 * `supports_credentials` must be true for SPA cookie auth (session + XSRF-TOKEN).
 * Allowed origins restricted to configured FRONTEND_URL env var.
 *
 * Security: credentials are only sent to explicitly listed origins — never wildcard.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3001')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
