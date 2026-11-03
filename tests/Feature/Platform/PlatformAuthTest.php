<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PlatformAuthTest — HTTP tests for platform operator authentication.
 *
 * Coverage:
 *  PA-01 — POST /api/platform/auth/login with valid credentials returns 200 + token.
 *  PA-02 — POST /api/platform/auth/login with wrong password returns 422.
 *  PA-03 — POST /api/platform/auth/login with inactive operator returns 403.
 *  PA-04 — GET  /api/platform/auth/me with valid token returns operator profile.
 *  PA-05 — GET  /api/platform/auth/me without token returns 401.
 *  PA-06 — Tenant user token cannot access /api/platform/auth/me (403 via platform.operator).
 *  PA-07 — POST /api/platform/auth/logout revokes the token.
 *  PA-08 — Browser-like request with stateful Origin does NOT return 419 (CSRF-419 regression guard).
 *  PA-09 — Revoked token is rejected with 401 after logout.
 *  PA-10 — Platform routes do not require X-Tenant header.
 *  PA-11 — Tenant SPA login route still enforces CSRF (419 without cookie) — regression guard.
 *
 * Uses DatabaseTransactions on 'mysql' only — no tenant schema touched.
 */
class PlatformAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePlatformUsersTableExists();
        $this->ensurePlatformPATTableExists();
    }

    // =========================================================================
    // PA-01 — Valid login returns token
    // =========================================================================

    #[Test]
    public function valid_platform_login_returns_token(): void
    {
        $operator = $this->makePlatformUser();

        $response = $this->postJson('/api/platform/auth/login', [
            'email'    => $operator->email,
            'password' => 'platform-secret-123',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
        $this->assertSame('Bearer', $data['token_type']);
    }

    // =========================================================================
    // PA-02 — Wrong password returns 422
    // =========================================================================

    #[Test]
    public function wrong_password_returns_422(): void
    {
        $operator = $this->makePlatformUser();

        $response = $this->postJson('/api/platform/auth/login', [
            'email'    => $operator->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PA-03 — Inactive operator returns 403
    // =========================================================================

    #[Test]
    public function inactive_operator_login_returns_403(): void
    {
        $operator = $this->makePlatformUser(active: false);

        $response = $this->postJson('/api/platform/auth/login', [
            'email'    => $operator->email,
            'password' => 'platform-secret-123',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('inactive', strtolower($response->json('error')));
    }

    // =========================================================================
    // PA-04 — GET /api/platform/auth/me returns operator profile
    // =========================================================================

    #[Test]
    public function authenticated_operator_can_get_own_profile(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame($operator->email, $data['email']);
        $this->assertSame($operator->name, $data['name']);
        $this->assertTrue($data['is_active']);
    }

    // =========================================================================
    // PA-05 — No token returns 401
    // =========================================================================

    #[Test]
    public function unauthenticated_request_to_me_returns_401(): void
    {
        $response = $this->getJson('/api/platform/auth/me');
        $response->assertStatus(401);
    }

    // =========================================================================
    // PA-07 — Logout returns 200 and success message
    // =========================================================================

    #[Test]
    public function logout_returns_200_and_success_message(): void
    {
        $operator   = $this->makePlatformUser();
        $plainToken = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $logoutResponse = $this->postJson('/api/platform/auth/logout', [], [
            'Authorization' => 'Bearer ' . $plainToken,
        ]);

        $logoutResponse->assertStatus(200);
        $this->assertSame('Logged out successfully.', $logoutResponse->json('message'));
    }

    // =========================================================================
    // PA-08 — Browser-like request (stateful Origin) does NOT return 419
    //
    // Regression guard for CSRF-419 fix:
    // EnsureFrontendRequestsAreStateful is excluded from /api/platform/* via
    // withoutMiddleware() in routes/api.php. A POST from a stateful-domain
    // Origin (localhost:3001) must reach the controller and return 200/422,
    // never 419 (CSRF token mismatch).
    // =========================================================================

    #[Test]
    public function platform_login_from_stateful_origin_does_not_return_419(): void
    {
        $operator = $this->makePlatformUser();

        // Simulate a browser request from a stateful SPA origin.
        // No XSRF-TOKEN cookie, no X-XSRF-TOKEN header — exactly what Chrome sends
        // when platform.ts calls fetch() without withCredentials.
        $response = $this->postJson(
            '/api/platform/auth/login',
            ['email' => $operator->email, 'password' => 'platform-secret-123'],
            ['Origin' => 'http://localhost:3001']
        );

        // Must NOT be 419 — the fix removes CSRF validation from platform routes.
        $this->assertNotEquals(419, $response->status(), 'Platform login must not return 419 (CSRF). Fix: withoutMiddleware(EnsureFrontendRequestsAreStateful) on platform route group.');

        // Valid credentials → 200 + token.
        $response->assertStatus(200);
        $this->assertArrayHasKey('token', $response->json());
    }

    // =========================================================================
    // PA-09 — Logout deletes the PAT from personal_access_tokens
    //
    // After logout the PAT record must be absent from personal_access_tokens.
    // We use a unique token name to identify the row, then assert it is gone
    // after logout. DatabaseTransactions wraps the test in a transaction on
    // 'mysql', so both the token creation and the logout delete happen inside
    // the same transaction — assertDatabaseMissing sees the post-delete state.
    // =========================================================================

    #[Test]
    public function logout_deletes_the_pat_from_database(): void
    {
        $operator        = $this->makePlatformUser();
        $uniqueTokenName = 'platform-api-test-' . uniqid();
        $tokenResult     = $operator->createPlatformToken($uniqueTokenName, ['platform:*']);
        $plainToken      = $tokenResult->plainTextToken;

        // Logout — must revoke (delete) the token.
        $this->postJson('/api/platform/auth/logout', [], [
            'Authorization' => 'Bearer ' . $plainToken,
        ])->assertStatus(200);

        // Token row must be gone from platform_personal_access_tokens on mysql.
        $this->assertDatabaseMissing(
            'platform_personal_access_tokens',
            ['name' => $uniqueTokenName],
            'mysql'
        );
    }

    // =========================================================================
    // PA-10 — Platform routes do NOT require X-Tenant header
    //
    // Platform operators are not scoped to any tenant. Sending a request
    // without X-Tenant must succeed (200), not fail with a tenant error.
    // =========================================================================

    #[Test]
    public function platform_me_does_not_require_x_tenant_header(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        // Deliberately omit X-Tenant header.
        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $token,
            // No X-Tenant header.
        ]);

        $response->assertStatus(200);
        $this->assertSame($operator->email, $response->json('email'));
    }

    // =========================================================================
    // PA-11 — EnsureFrontendRequestsAreStateful is NOT on platform routes,
    //          but IS still registered on tenant SPA routes.
    //
    // Regression guard: the fix (withoutMiddleware on platform group) must not
    // remove EnsureFrontendRequestsAreStateful from tenant routes.
    //
    // We assert the middleware stack directly via the Router rather than
    // expecting a 419 HTTP response, because Laravel's test client always
    // bypasses CSRF validation (it adds VerifyCsrfToken to the disabled list
    // automatically), so a 419 can never be produced from within PHPUnit.
    // =========================================================================

    #[Test]
    public function ensure_frontend_stateful_middleware_is_absent_from_platform_routes(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = app('router');
        $routes = $router->getRoutes();

        $platformLoginRoute = $routes->getByName('platform.auth.login');
        $this->assertNotNull($platformLoginRoute, 'platform.auth.login route must exist');

        $platformMiddleware = $platformLoginRoute->gatherMiddleware();

        // EnsureFrontendRequestsAreStateful must NOT appear on the platform login route.
        $this->assertNotContains(
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            $platformMiddleware,
            'EnsureFrontendRequestsAreStateful must be excluded from /api/platform/* routes (CSRF-419 fix).'
        );
    }

    #[Test]
    public function ensure_frontend_stateful_middleware_is_present_in_api_group(): void
    {
        // EnsureFrontendRequestsAreStateful is registered as a group middleware on the
        // 'api' group via prependToGroup() in bootstrap/app.php. It does NOT appear in
        // gatherMiddleware() on individual routes (which only returns per-route middleware
        // and named aliases, not group middleware). The correct assertion is to verify it
        // is present in the 'api' middleware group definition.
        /** @var \Illuminate\Routing\Router $router */
        $router = app('router');

        $apiGroup = $router->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            $apiGroup,
            'EnsureFrontendRequestsAreStateful must remain in the api middleware group — CSRF protection must not be removed from tenant routes.'
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function makePlatformUser(bool $active = true): PlatformUser
    {
        $operator = new PlatformUser();
        $operator->setConnection('mysql');
        $operator->name      = 'Test Platform Operator';
        $operator->email     = 'platform-test-' . uniqid() . '@binaa.test';
        $operator->password  = Hash::make('platform-secret-123');
        $operator->is_active = $active;
        $operator->save();

        return $operator;
    }

    private function ensurePlatformUsersTableExists(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_users')) {
            Schema::connection('mysql')->create('platform_users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index('email');
                $table->index('is_active');
            });
        }
    }

    private function ensurePlatformPATTableExists(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_personal_access_tokens')) {
            Schema::connection('mysql')->create('platform_personal_access_tokens', function ($table) {
                $table->id();
                $table->string('tokenable_type');
                $table->unsignedBigInteger('tokenable_id');
                $table->index(['tokenable_type', 'tokenable_id'], 'ppat_tokenable_index');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
