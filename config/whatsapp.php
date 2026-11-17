<?php

declare(strict_types=1);

/**
 * Platform WhatsApp bridge (Baileys) — local/internal only.
 * Unofficial WhatsApp Web protocol. Not Meta Business API.
 * All feature flags default OFF until Platform Admin enables after successful link.
 */
return [
    'enabled' => (bool) env('WHATSAPP_ENABLED', false),
    'otp_enabled' => (bool) env('WHATSAPP_OTP_ENABLED', false),
    'notifications_enabled' => (bool) env('WHATSAPP_NOTIFICATIONS_ENABLED', false),
    'use_fake_bridge' => (bool) env('WHATSAPP_USE_FAKE_BRIDGE', false),
    'bridge' => [
        'base_url' => rtrim((string) env('WHATSAPP_BRIDGE_URL', 'http://127.0.0.1:3105'), '/'),
        'shared_secret' => (string) env('WHATSAPP_BRIDGE_SECRET', ''),
        'timeout_seconds' => (int) env('WHATSAPP_BRIDGE_TIMEOUT', 10),
    ],
];
