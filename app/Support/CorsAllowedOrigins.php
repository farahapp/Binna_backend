<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Builds the explicit CORS origin list for Sanctum SPA requests.
 *
 * FRONTEND_URL is the primary web origin. CORS_ALLOWED_ORIGINS adds other
 * exact origins, such as a dedicated admin host. Production never falls
 * back to localhost, and a wildcard is rejected.
 */
final class CorsAllowedOrigins
{
    /**
     * @return list<string>
     */
    public static function resolve(?string $appEnv, ?string $frontendUrl, ?string $extraOrigins): array
    {
        $environment = $appEnv === null || $appEnv === '' ? 'production' : $appEnv;
        $primary = self::primary($environment, $frontendUrl);
        $extras = self::split($extraOrigins);
        $origins = array_values(array_unique(array_filter(
            array_merge($primary === null ? [] : [$primary], $extras),
            static fn (string $origin): bool => $origin !== ''
        )));

        foreach ($origins as $origin) {
            self::assertOrigin($origin, $environment);
        }

        if ($environment === 'production' && $origins === []) {
            throw new InvalidArgumentException(
                'FRONTEND_URL is required in production. CORS will not fall back to localhost.'
            );
        }

        return $origins;
    }

    private static function primary(string $environment, ?string $frontendUrl): ?string
    {
        if (is_string($frontendUrl) && trim($frontendUrl) !== '') {
            return trim($frontendUrl);
        }

        if (in_array($environment, ['local', 'testing'], true)) {
            return 'http://localhost:3001';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function split(?string $extraOrigins): array
    {
        if ($extraOrigins === null || trim($extraOrigins) === '') {
            return [];
        }

        return array_map('trim', explode(',', $extraOrigins));
    }

    private static function assertOrigin(string $origin, string $environment): void
    {
        if ($origin === '*') {
            throw new InvalidArgumentException('CORS must not allow a wildcard origin when credentials are enabled.');
        }

        if (!preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::\d{1,5})?\z#', $origin)) {
            throw new InvalidArgumentException('CORS origin must be a scheme and host, without a path or wildcard.');
        }

        if ($environment === 'production' && !str_starts_with($origin, 'https://')) {
            throw new InvalidArgumentException('Production CORS origins must use https.');
        }
    }
}
