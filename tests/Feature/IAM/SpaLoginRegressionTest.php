<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\IAM\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SpaLoginRegressionTest
 *
 * Regression test for the SPA Login HTTP 500 failure:
 *   {"message":"Session store not set on request.","exception":"RuntimeException"}
 *   Thrown at: AuthController::spaLogin() → $request->session()->regenerate()
 *
 * Root cause: POST /api/auth/spa/login is in the 'api' middleware group.
 * EnsureFrontendRequestsAreStateful is prepended to the 'api' group but only
 * activates the session middleware (StartSession, EncryptCookies, etc.) when
 * the request originates from a stateful domain (SANCTUM_STATEFUL_DOMAINS).
 * When the request has no Origin/Referer matching a stateful domain, the session
 * is never started, and $request->session() throws RuntimeException.
 *
 * Intended behavior (C-002 §26):
 *   - SPA clients on stateful domains (localhost:3000, localhost:3001, etc.)
 *     authenticate via session cookie — no PAT issued.
 *   - The Origin header must match a stateful domain for the session to be active.
 *   - Non-stateful origins (e.g. Android app) must use POST /api/auth/login (PAT).
 *
 * Test strategy:
 *   - Stateful-origin request (Origin: http://localhost:3000) → session active → 200.
 *   - Non-stateful-origin request (no Origin / wrong Origin) → session not started → 500.
 *     This documents the current behavior and confirms the fix scope.
 *
 * Constraints:
 *  - No production code modified in this test file.
 *  - Uses the same tenant-setup pattern as existing IAM tests.
 *  - Does not weaken authentication or tenant isolation.
 *  - Password is generated per-run and never stored.
 */
class SpaLoginRegressionTest extends TestCase
{
    private const SLUG   = 'spa-regression-tenant';
    private const SCHEMA = 'binaa2_testing';
    private const EMAIL  = 'spa-regression@binaa.test';

    private string $password = '';

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->password = bin2hex(random_bytes(16));
        $this->setUpTenantAndUser();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    // =========================================================================
    // Regression Tests
    // =========================================================================

    /**
     * @test
     *
     * PRIMARY REGRESSION: SPA login from a stateful domain must return 200.
     *
     * EnsureFrontendRequestsAreStateful activates the session middleware when
     * the request Origin matches SANCTUM_STATEFUL_DOMAINS (includes localhost:3000).
     * With the session active, $request->session()->regenerate() succeeds.
     *
     * Expected: 200 with user payload (no PAT — session cookie auth).
     * Failure before fix: 500 "Session store not set on request."
     */
    public function spa_login_returns_200_from_stateful_origin(): void
    {
        $response = $this->withHeaders([
            'X-Tenant'    => self::SLUG,
            'Origin'      => 'http://localhost:3000',
            'Referer'     => 'http://localhost:3000/',
            'Accept'      => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/auth/spa/login', [
            'email'    => self::EMAIL,
            'password' => $this->password,
        ]);

        // Must not be 500 "Session store not set" — that is the regression.
        $response->assertStatus(200);
        $response->assertJsonStructure(['user' => ['id', 'name', 'email']]);
        $response->assertJsonPath('user.email', self::EMAIL);
    }

    /**
     * @test
     *
     * FIX C REGRESSION: SPA login from a non-stateful origin (no Origin header)
     * must return 422 with a clear message directing the caller to use PAT login.
     *
     * Before Fix C: returned 500 "Session store not set on request." (RuntimeException).
     * After Fix C:  returns 422 with a clear message — no unhandled exception.
     *
     * The fix adds a $request->hasSession() guard in AuthController::spaLogin()
     * before calling $request->session()->regenerate(). Non-stateful origins
     * (e.g. Android app, p2_e2e_live_test.php harness) receive a clean 422
     * instead of a 500 crash.
     */
    public function spa_login_returns_422_from_non_stateful_origin(): void
    {
        $response = $this->withHeaders([
            'X-Tenant'     => self::SLUG,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            // No Origin header — simulates the p2_e2e_live_test.php harness behavior.
        ])->postJson('/api/auth/spa/login', [
            'email'    => self::EMAIL,
            'password' => $this->password,
        ]);

        // After Fix C: clean 422 instead of 500 crash.
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'SPA login requires a stateful origin. Use POST /api/auth/login for token-based authentication.');
    }

    /**
     * @test
     *
     * WRONG CREDENTIALS: SPA login with wrong password from stateful origin
     * must return 401 (not 500 or 422).
     */
    public function spa_login_returns_401_with_wrong_password_from_stateful_origin(): void
    {
        $response = $this->withHeaders([
            'X-Tenant'     => self::SLUG,
            'Origin'       => 'http://localhost:3000',
            'Referer'      => 'http://localhost:3000/',
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/auth/spa/login', [
            'email'    => self::EMAIL,
            'password' => 'definitely-wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    /**
     * @test
     *
     * TENANT GUARD: SPA login without X-Tenant header must return 400.
     * Confirms ResolveTenantContext middleware is active on the SPA login route.
     */
    public function spa_login_returns_400_without_x_tenant_header(): void
    {
        $response = $this->withHeaders([
            'Origin'       => 'http://localhost:3000',
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/auth/spa/login', [
            'email'    => self::EMAIL,
            'password' => $this->password,
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'X-Tenant header is required.']);
    }

    /**
     * @test
     *
     * UNKNOWN TENANT: SPA login with an unknown tenant slug must return 404.
     */
    public function spa_login_returns_404_for_unknown_tenant(): void
    {
        $response = $this->withHeaders([
            'X-Tenant'     => 'nonexistent-tenant-xyz',
            'Origin'       => 'http://localhost:3000',
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/auth/spa/login', [
            'email'    => self::EMAIL,
            'password' => $this->password,
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // Infrastructure
    // =========================================================================

    private function setUpTenantAndUser(): void
    {
        // Ensure shared schema has tenants table.
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/shared', '--force' => true]);
        }

        // Insert tenant record (idempotent).
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => self::SLUG,
            'name'        => 'SPA Regression Tenant',
            'schema_name' => self::SCHEMA,
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Point tenant connection at the test schema.
        $this->switchTenantConnection(self::SCHEMA);

        // Ensure IAM tables exist.
        $this->ensureTenantTables();

        // Create or update the test user.
        $user = User::on('tenant')->where('email', self::EMAIL)->first();
        if (! $user) {
            $user = new User();
            $user->setConnection('tenant');
            $user->name  = 'SPA Regression User';
            $user->email = self::EMAIL;
        }
        $user->password = Hash::make($this->password);
        $user->save();
    }

    private function switchTenantConnection(string $schemaName): void
    {
        $base = Config::get('database.connections.mysql');
        Config::set('database.connections.tenant', array_merge($base, [
            'database' => $schemaName,
        ]));
        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    private function ensureTenantTables(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('migrations')) {
            $schema->create('migrations', function ($table) {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
        }

        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection('tenant');
        $migrator->run(database_path('migrations/iam'));
        $migrator->run(database_path('migrations/audit'));
    }

    private function cleanUp(): void
    {
        try {
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->where('email', self::EMAIL)->delete();
        } catch (\Throwable) {}

        try {
            DB::connection('mysql')->table('tenants')->where('slug', self::SLUG)->delete();
        } catch (\Throwable) {}
    }
}
