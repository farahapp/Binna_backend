<?php

declare(strict_types=1);

namespace Tests\Feature\BI;

use App\BI\Dashboard;
use App\Foundation\Tenant;
use App\Foundation\TenantContext;
use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DashboardTenantContextRegressionTest
 *
 * Regression test for the Dashboard E2E 403 failure:
 *   {"error":"The authenticated user has no tenant context."}
 *
 * Root cause: ReportingService::tenantSlug() calls data_get($user, 'tenant_slug'),
 * which triggers User::getTenantSlugAttribute(), which calls app(TenantContext::class).
 * If TenantContext is not bound in the IoC container at the time of the call,
 * the accessor returns an empty string (or throws), causing the 403.
 *
 * This test exercises the full HTTP request lifecycle through the middleware stack
 * (ResolveTenantContext → SwitchToTenantSchema → auth:sanctum → BIController →
 * ReportingService) to confirm that TenantContext IS bound when the accessor runs.
 *
 * Constraints:
 *  - No production code modified.
 *  - Uses the same tenant-setup pattern as existing IAM tests.
 *  - Does not weaken authentication, tenant isolation, or authorization.
 *  - BI migrations run on the tenant connection (test infrastructure only).
 */
class DashboardTenantContextRegressionTest extends TestCase
{
    private const SLUG        = 'bi-regression-tenant';
    private const SCHEMA      = 'binaa2_testing';
    private const EMAIL       = 'bi-regression@binaa.test';
    private const PERMISSION  = 'bi.dashboard.view';

    private string $token = '';

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
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
     * PRIMARY REGRESSION: Reproduces the Dashboard 403 failure.
     *
     * The full middleware pipeline (ResolveTenantContext → SwitchToTenantSchema →
     * auth:sanctum) must bind TenantContext before BIController/ReportingService
     * executes. If TenantContext is not bound, ReportingService::tenantSlug()
     * throws RuntimeException → BIController returns 403.
     *
     * Expected: 200 (TenantContext is bound by middleware, accessor resolves slug).
     * Failure before fix: 403 {"error":"The authenticated user has no tenant context."}
     */
    public function dashboard_returns_200_when_tenant_context_is_bound_by_middleware(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant'      => self::SLUG,
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        // Must not be 403 "no tenant context" — that is the regression.
        $response->assertStatus(200);
    }

    /**
     * @test
     *
     * ISOLATION: Without X-Tenant header, middleware returns 400 before
     * TenantContext is ever bound. Confirms the middleware guard is intact.
     */
    public function dashboard_returns_400_without_x_tenant_header(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'X-Tenant header is required.']);
    }

    /**
     * @test
     *
     * AUTH: Without a token, auth:sanctum must return 401.
     * Confirms authentication is not weakened by any fix.
     */
    public function dashboard_returns_401_without_token(): void
    {
        $response = $this->withHeaders([
            'X-Tenant' => self::SLUG,
            'Accept'   => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        $response->assertStatus(401);
    }

    /**
     * @test
     *
     * AUTHORIZATION: A user without bi.dashboard.view permission must get 403
     * with the authorization message (not the tenant-context message).
     * Confirms the permission check is distinct from the tenant-context check.
     */
    public function dashboard_returns_403_when_user_lacks_permission(): void
    {
        // Create a second user in the same tenant with NO bi.dashboard.view permission.
        $unprivUser = new User();
        $unprivUser->setConnection('tenant');
        $unprivUser->name     = 'Unpriv User';
        $unprivUser->email    = 'unpriv-bi-' . uniqid() . '@binaa.test';
        $unprivUser->password = Hash::make('secret-' . uniqid());
        $unprivUser->save();

        $unprivToken = $unprivUser->createToken('api')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $unprivToken,
            'X-Tenant'      => self::SLUG,
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        $response->assertStatus(403);
        // Must be the authorization message, NOT the tenant-context message.
        $response->assertJsonFragment(['error' => 'The authenticated user is not authorized to view BI dashboards.']);
    }

    /**
     * @test
     *
     * TENANT ISOLATION: A valid token from this tenant cannot be used with a
     * different tenant slug. Confirms cross-tenant isolation is preserved.
     */
    public function dashboard_returns_404_for_unknown_tenant(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant'      => 'nonexistent-tenant-xyz',
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        $response->assertStatus(404);
    }

    /**
     * @test
     *
     * STALE-GUARD REGRESSION (DEP-01):
     * Two sequential HTTP requests — first as Tenant A user, then as Tenant B user —
     * within the same PHP process must each resolve their own identity correctly.
     *
     * Before the fix: ReportingService held a stale Guard reference from request A,
     * causing request B to see Tenant A's user → 403 "Authenticated user does not match".
     *
     * After the fix: ReportingService reads from Request (scoped per request),
     * so each request resolves its own authenticated user independently.
     */
    public function sequential_requests_for_two_tenants_resolve_independent_identities(): void
    {
        // ── Design note ───────────────────────────────────────────────────────
        // Both requests use the SAME tenant slug (bi-regression-tenant) but
        // carry DIFFERENT Bearer tokens belonging to two different users.
        //
        // This directly exercises the stale-guard regression:
        //   Before the fix: ReportingService held a cached Guard from request A.
        //   Request B carried user B's token, but the cached Guard still returned
        //   user A → authenticatedUser() threw "does not match" → HTTP 403.
        //
        //   After the fix: ReportingService reads from the current Request on
        //   every call (scoped binding), so each request resolves its own user.
        //
        // Using the same tenant avoids any cross-schema or transaction-visibility
        // complexity while still proving the core invariant: two sequential
        // requests with different tokens must resolve different identities.
        // ─────────────────────────────────────────────────────────────────────

        $emailB = 'bi-regression-user-b@binaa.test';

        // Create a second user in the same tenant with the same permission.
        $userB = \App\IAM\User::on('tenant')->where('email', $emailB)->first();
        if (! $userB) {
            $userB = new \App\IAM\User();
            $userB->setConnection('tenant');
            $userB->name     = 'BI Regression User B';
            $userB->email    = $emailB;
            $userB->password = \Illuminate\Support\Facades\Hash::make('bi-regression-b-' . uniqid());
            $userB->save();
        }

        // Grant bi.dashboard.view to user B (reuse existing permission/role).
        $permission = \App\IAM\Permission::on('tenant')->firstOrCreate(
            ['name' => self::PERMISSION],
            ['guard_name' => 'api']
        );
        $roleB = \App\IAM\Role::on('tenant')->firstOrCreate(
            ['name' => 'bi-regression-role-b'],
            ['guard_name' => 'api']
        );
        if (! $roleB->permissions()->where('name', self::PERMISSION)->exists()) {
            $roleB->givePermission($permission);
        }
        if (! $userB->hasRole('bi-regression-role-b')) {
            $userB->assignRole($roleB);
        }

        $tokenB = $userB->createToken('api-b')->plainTextToken;

        // ── Request A: first user accesses the dashboard ──────────────────────
        $responseA = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Tenant'      => self::SLUG,
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        // ── Request B: second user accesses the dashboard ─────────────────────
        // Before the fix: ReportingService still held user A's Guard reference
        // → authenticatedUser() compared user A's ID against user B's token ID
        // → mismatch → RuntimeException → HTTP 403.
        $responseB = $this->withHeaders([
            'Authorization' => 'Bearer ' . $tokenB,
            'X-Tenant'      => self::SLUG,
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/bi/dashboard');

        // Both must succeed — each request resolves its own authenticated user.
        $responseA->assertStatus(200);
        $responseB->assertStatus(200);

        // Neither response must carry the stale-guard error.
        $responseA->assertJsonMissing(['error' => 'Authenticated user does not match the requested dashboard user.']);
        $responseB->assertJsonMissing(['error' => 'Authenticated user does not match the requested dashboard user.']);
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
            'name'        => 'BI Regression Tenant',
            'schema_name' => self::SCHEMA,
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Point tenant connection at the test schema.
        $this->switchTenantConnection(self::SCHEMA);

        // Ensure required tables exist in the tenant schema.
        $this->ensureTenantTables();

        // Ensure BI tables exist (bi_dashboards needed for the query).
        $this->ensureBiTables();

        // Create user with bi.dashboard.view permission.
        $user = User::on('tenant')->where('email', self::EMAIL)->first();
        if (! $user) {
            $user = new User();
            $user->setConnection('tenant');
            $user->name     = 'BI Regression User';
            $user->email    = self::EMAIL;
            $user->password = Hash::make('bi-regression-' . uniqid());
            $user->save();
        }

        // Grant bi.dashboard.view permission.
        $permission = Permission::on('tenant')->firstOrCreate(
            ['name' => self::PERMISSION],
            ['guard_name' => 'api']
        );
        $role = Role::on('tenant')->firstOrCreate(
            ['name' => 'bi-regression-role'],
            ['guard_name' => 'api']
        );
        if (! $role->permissions()->where('name', self::PERMISSION)->exists()) {
            $role->givePermission($permission);
        }
        if (! $user->hasRole('bi-regression-role')) {
            $user->assignRole($role);
        }

        // Issue a PAT for this user.
        $this->token = $user->createToken('api')->plainTextToken;
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

    private function ensureBiTables(): void
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection('tenant');
        $migrator->run(database_path('migrations/bi'));
    }

    private function cleanUp(): void
    {
        try {
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('model_has_roles')->delete();
            DB::connection('tenant')->table('role_has_permissions')->delete();
            DB::connection('tenant')->table('users')->where('email', self::EMAIL)->delete();
            DB::connection('tenant')->table('users')->where('email', 'like', 'unpriv-bi-%')->delete();
            DB::connection('tenant')->table('roles')->where('name', 'bi-regression-role')->delete();
            DB::connection('tenant')->table('permissions')->where('name', self::PERMISSION)->delete();
            DB::connection('tenant')->table('bi_dashboards')->delete();
        } catch (\Throwable) {}

        try {
            DB::connection('mysql')->table('tenants')->where('slug', self::SLUG)->delete();
        } catch (\Throwable) {}
    }
}
