<?php

declare(strict_types=1);

/**
 * TenantLoginContextFixTest.php
 *
 * BINAA2 — TASK: Tenant Login Context Fix
 *
 * Verifies the corrected behaviour of:
 *   - ResolveTenantFromLoginToken  (binds TenantContext via App::instance, no header injection)
 *   - ResolveTenantContext         (honours pre-bound context; X-Tenant cannot override it)
 *
 * Test matrix:
 *  TC-01  resolution_token → PAT login succeeds without X-Tenant header
 *  TC-02  resolution_token → SPA login (session cookie) preserves its response contract
 *  TC-03  missing resolution_token → 422
 *  TC-04  expired resolution_token → 422
 *  TC-05  tampered resolution_token → 422
 *  TC-06  X-Tenant bypass attempt with valid token → X-Tenant is ignored (token wins)
 *  TC-07  legacy login with X-Tenant header (no token) → succeeds (PATH B unchanged)
 *  TC-08  platform auth routes are unaffected (no tenant middleware)
 *  TC-09  /api/auth/me after token-based login returns authenticated user
 *  TC-10  platform/tenant separation: platform route rejects tenant PAT
 *  TC-11  a PAT issued for tenant A cannot access tenant B
 *
 * Constraints:
 *  - No production configuration or database schema modified.
 *  - No .env / DB / user modifications.
 *  - Uses the existing bootstrap_iam_tenant.php helper.
 *  - Password never printed or stored.
 */

namespace Tests\Feature\IAM;

use App\Foundation\Tenant;
use App\Foundation\TenantContext;
use App\IAM\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantLoginContextFixTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Shared bootstrap state
    // -------------------------------------------------------------------------

    private static bool   $bootstrapped = false;
    private static array  $ctx          = [];
    private static string $password     = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            self::$password = bin2hex(random_bytes(16));

            require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
            self::$ctx          = bootstrapAuthSmokeTenant(self::$password);
            self::$bootstrapped = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        cleanupAuthSmokeTenant();

        self::$bootstrapped = false;
        self::$ctx          = [];
        self::$password     = '';

        parent::tearDownAfterClass();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Build a valid, unexpired resolution_token for the smoke tenant. */
    private function makeToken(array $overrides = []): string
    {
        /** @var Tenant $tenant */
        $tenant = self::$ctx['tenant'];

        $payload = array_merge([
            'tenant_id'  => $tenant->id,
            'slug'       => $tenant->slug,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], $overrides);

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** Switch the 'tenant' DB connection to the smoke schema. */
    private function switchToSmokeSchema(): void
    {
        $schemaName = self::$ctx['tenant']->schema_name;
        Config::set('database.connections.tenant.database', $schemaName);
        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    // =========================================================================
    // TC-01 — resolution_token → PAT login succeeds without X-Tenant header
    // =========================================================================

    #[Test]
    public function tc01_pat_login_via_resolution_token_without_x_tenant(): void
    {
        $this->switchToSmokeSchema();

        $token = $this->makeToken();

        $response = $this->postJson('/api/auth/tenant/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => $token,
        ]);
        // No X-Tenant header sent.

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'tenant_slug']);
        $response->assertJsonPath('tenant_slug', self::$ctx['tenant']->slug);
        $this->assertNotEmpty($response->json('token'));
    }

    // =========================================================================
    // TC-02 — resolution_token → SPA login (session) succeeds without X-Tenant
    // =========================================================================

    #[Test]
    public function tc02_spa_login_via_resolution_token_without_x_tenant(): void
    {
        $this->switchToSmokeSchema();

        $token = $this->makeToken();

        $response = $this->postJson('/api/auth/tenant/spa/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => $token,
        ], ['Origin' => 'http://localhost:3000']);

        // SPA login returns 200 with user data (no PAT).
        $response->assertStatus(200);
        $response->assertJsonStructure(['user' => ['id', 'name', 'email']]);
        $response->assertJsonMissingPath('tenant_slug');
    }

    // =========================================================================
    // TC-03 — missing resolution_token → 422
    // =========================================================================

    #[Test]
    public function tc03_missing_resolution_token_returns_422(): void
    {
        // No X-Tenant, no resolution_token → ResolveTenantFromLoginToken rejects.
        $response = $this->postJson('/api/auth/tenant/login', [
            'email'    => 'any@example.com',
            'password' => 'irrelevant',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // TC-04 — expired resolution_token → 422
    // =========================================================================

    #[Test]
    public function tc04_expired_resolution_token_returns_422(): void
    {
        $token = $this->makeToken(['expires_at' => now()->subMinute()->timestamp]);

        $response = $this->postJson('/api/auth/tenant/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => $token,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // TC-05 — tampered resolution_token → 422
    // =========================================================================

    #[Test]
    public function tc05_tampered_resolution_token_returns_422(): void
    {
        $response = $this->postJson('/api/auth/tenant/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => 'this-is-not-a-valid-encrypted-token',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // TC-06 — X-Tenant bypass attempt: valid token + wrong X-Tenant → token wins
    //
    // Attacker sends a valid resolution_token for tenant A but also sends
    // X-Tenant: tenant-b hoping to hijack the context.
    // Expected: ResolveTenantFromLoginToken binds context for tenant A;
    //           ResolveTenantContext detects the pre-bound context and ignores
    //           X-Tenant entirely → login proceeds against tenant A's schema.
    // =========================================================================

    #[Test]
    public function tc06_x_tenant_cannot_override_resolution_token(): void
    {
        $this->switchToSmokeSchema();

        $token = $this->makeToken(); // valid token for 'auth-smoke'

        // Send X-Tenant pointing to a completely different (non-existent) tenant.
        $response = $this->postJson('/api/auth/tenant/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => $token,
        ], ['X-Tenant' => 'totally-different-tenant-that-does-not-exist']);

        // The token-based context wins; X-Tenant is ignored.
        // Login should succeed (200) against the correct tenant.
        $response->assertStatus(200);
        $response->assertJsonPath('tenant_slug', self::$ctx['tenant']->slug);
    }

    // =========================================================================
    // TC-07 — legacy login with X-Tenant header (no token) → PATH B unchanged
    // =========================================================================

    #[Test]
    public function tc07_legacy_login_with_x_tenant_header_still_works(): void
    {
        $this->switchToSmokeSchema();

        // Classic flow: X-Tenant header, no resolution_token.
        $response = $this->postJson('/api/auth/login', [
            'email'    => self::$ctx['user']->email,
            'password' => self::$password,
        ], ['X-Tenant' => self::$ctx['tenant']->slug]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token']);
    }

    // =========================================================================
    // TC-11 — PAT from tenant A cannot authenticate in tenant B
    // =========================================================================

    #[Test]
    public function tc11_pat_from_one_tenant_cannot_access_another_tenant(): void
    {
        $passwordB = bin2hex(random_bytes(16));
        $tenantB = \TenantTestHarness::provision($passwordB);

        try {
            $this->switchToSmokeSchema();

            $loginResponse = $this->postJson('/api/auth/tenant/login', [
                'email'            => self::$ctx['user']->email,
                'password'         => self::$password,
                'resolution_token' => $this->makeToken(),
            ]);

            $loginResponse->assertStatus(200);
            $patA = $loginResponse->json('token');

            // Each HTTP request in this feature test shares the application
            // container, unlike separate production requests.
            App::forgetInstance(TenantContext::class);

            $response = $this->getJson('/api/auth/me', [
                'Authorization' => "Bearer {$patA}",
                'X-Tenant' => $tenantB->slug,
            ]);

            $response->assertUnauthorized();
            $response->assertJsonMissingPath('email');
        } finally {
            $tenantB->cleanup();
            $this->switchToSmokeSchema();
        }
    }

    // =========================================================================
    // TC-08 — platform auth routes are unaffected (no tenant middleware)
    // =========================================================================

    #[Test]
    public function tc08_platform_login_route_has_no_tenant_middleware(): void
    {
        // POST /api/platform/auth/login with wrong credentials → 422 (validation)
        // NOT 400 (missing X-Tenant) — proves tenant middleware is absent.
        $response = $this->postJson('/api/platform/auth/login', [
            'email'    => 'nonexistent-platform@binaa.test',
            'password' => 'wrong',
        ]);

        // Should be 401 (bad credentials) or 422 (validation), never 400 (X-Tenant).
        $this->assertNotEquals(400, $response->status(),
            'Platform login must not require X-Tenant header (got 400).');
        $this->assertContains($response->status(), [401, 422],
            "Expected 401 or 422 from platform login, got {$response->status()}.");
    }

    // =========================================================================
    // TC-09 — /api/auth/me after token-based login returns authenticated user
    // =========================================================================

    #[Test]
    public function tc09_auth_me_after_token_login_returns_user(): void
    {
        $this->switchToSmokeSchema();

        // Step 1: login via resolution_token.
        $loginResponse = $this->postJson('/api/auth/tenant/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => $this->makeToken(),
        ]);

        $loginResponse->assertStatus(200);
        $pat = $loginResponse->json('token');
        $this->assertNotEmpty($pat);

        // Step 2: call /api/auth/me with the issued PAT + X-Tenant.
        $meResponse = $this->getJson('/api/auth/me', [
            'Authorization' => "Bearer {$pat}",
            'X-Tenant'      => self::$ctx['tenant']->slug,
        ]);

        $meResponse->assertStatus(200);
        $meResponse->assertJsonPath('email', self::$ctx['user']->email);
    }

    // =========================================================================
    // TC-10 — platform/tenant separation: platform route rejects tenant PAT
    // =========================================================================

    #[Test]
    public function tc10_platform_route_rejects_tenant_pat(): void
    {
        $this->switchToSmokeSchema();

        // Obtain a tenant PAT.
        $loginResponse = $this->postJson('/api/auth/tenant/login', [
            'email'            => self::$ctx['user']->email,
            'password'         => self::$password,
            'resolution_token' => $this->makeToken(),
        ]);

        $loginResponse->assertStatus(200);
        $tenantPat = $loginResponse->json('token');

        // Attempt to use the tenant PAT on a platform-only route.
        $platformResponse = $this->getJson('/api/platform/auth/me', [
            'Authorization' => "Bearer {$tenantPat}",
        ]);

        // Must be rejected — 401 (unauthenticated) or 403 (wrong guard/role).
        $this->assertContains($platformResponse->status(), [401, 403],
            "Tenant PAT must not access platform routes (got {$platformResponse->status()}).");
    }
}
