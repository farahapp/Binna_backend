<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'otp' => [
        'expires_minutes' => 5,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'session_hours' => 8,
    ],
    'representative_invite' => [
        'expires_minutes' => 30,
    ],
];
