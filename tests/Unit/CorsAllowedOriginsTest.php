<?php

namespace Tests\Unit;

use App\Support\CorsAllowedOrigins;
use Fruitcake\Cors\CorsService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CorsAllowedOriginsTest extends TestCase
{
    private const ADMIN = 'https://admin.binaaqatar.com';

    private const APEX = 'https://binaaqatar.com';

    public function test_production_allows_the_admin_host_and_the_configured_apex(): void
    {
        $origins = CorsAllowedOrigins::resolve('production', self::APEX, self::ADMIN);

        $this->assertSame([self::APEX, self::ADMIN], $origins);
        $this->assertNotContains('*', $origins);
        $this->assertNotContains('http://localhost:3001', $origins);
    }

    public function test_preflight_echoes_only_an_allowed_origin_and_keeps_credentials(): void
    {
        $service = $this->service(CorsAllowedOrigins::resolve('production', self::APEX, self::ADMIN));

        $allowed = $service->handlePreflightRequest($this->preflight(self::ADMIN));
        $this->assertSame(204, $allowed->getStatusCode());
        $this->assertSame(self::ADMIN, $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('POST', (string) $allowed->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('x-xsrf-token', strtolower((string) $allowed->headers->get('Access-Control-Allow-Headers')));
        $this->assertStringContainsString('content-type', strtolower((string) $allowed->headers->get('Access-Control-Allow-Headers')));
        $this->assertNotSame('*', $allowed->headers->get('Access-Control-Allow-Origin'));

        $apex = $service->handlePreflightRequest($this->preflight(self::APEX));
        $this->assertSame(self::APEX, $apex->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_preflight_rejects_a_different_host_scheme_or_port(): void
    {
        $service = $this->service(CorsAllowedOrigins::resolve('production', self::APEX, self::ADMIN));

        foreach ([
            'https://evil.example',
            'http://admin.binaaqatar.com',
            'https://admin.binaaqatar.com:8443',
            'https://www.binaaqatar.com',
            'https://api.binaaqatar.com',
        ] as $origin) {
            $response = $service->handlePreflightRequest($this->preflight($origin));
            $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'), $origin);
            $this->assertFalse($service->isOriginAllowed($this->preflight($origin)), $origin);
        }
    }

    public function test_production_does_not_use_localhost_when_frontend_url_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CorsAllowedOrigins::resolve('production', null, '');
    }

    public function test_local_and_testing_keep_the_localhost_default(): void
    {
        $this->assertSame(['http://localhost:3001'], CorsAllowedOrigins::resolve('local', null, ''));
        $this->assertSame(['http://localhost:3001'], CorsAllowedOrigins::resolve('testing', '   ', null));
    }

    public function test_wildcard_path_and_insecure_production_origins_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CorsAllowedOrigins::resolve('production', self::APEX, '*');
    }

    public function test_production_rejects_an_origin_with_a_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CorsAllowedOrigins::resolve('production', 'https://admin.binaaqatar.com/platform/login', '');
    }

    public function test_production_rejects_http(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CorsAllowedOrigins::resolve('production', 'http://admin.binaaqatar.com', '');
    }

    /**
     * @param list<string> $origins
     */
    private function service(array $origins): CorsService
    {
        return new CorsService([
            'allowed_origins' => $origins,
            'allowed_methods' => ['*'],
            'allowed_headers' => ['*'],
            'supports_credentials' => true,
        ]);
    }

    private function preflight(string $origin): Request
    {
        return Request::create('https://api.binaaqatar.com/api/platform/auth/web/login', 'OPTIONS', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
        ]);
    }
}
