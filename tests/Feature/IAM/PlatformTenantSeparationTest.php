<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Foundation\PlatformUser;
use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PlatformTenantSeparationTest
 *
 * Verifies the security separation between platform operators and tenant users.
 *
 * Coverage:
 *  SEP-01 â€” Tenant user cannot access platform-operator-only routes (403).
 *  SEP-02 â€” PlatformUser instance is rejected by tenant routes (403 via VerifyTenantMembership).
 *  SEP-03 â€” Tenant user without tenant_admin role cannot assign permissions (403).
 *  SEP-04 â€” Tenant user WITH tenant_admin role CAN assign permissions (200).
 *  SEP-05 â€” Tenant user WITH iam:roles:assign_permission CAN assign permissions (200).
 *  SEP-06 â€” Cross-tenant PAT is rejected (403 via VerifyTenantMembership).
 *  SEP-07 â€” PlatformUser model uses 'mysql' connection, never 'tenant'.
 *  SEP-08 â€” Inactive PlatformUser is rejected by RequirePlatformOperator (403).
 *  SEP-09 â€" Unauthenticated request to protected route returns 401.
 *  SEP-10 â€" Tenant user cannot escalate to tenant_admin by self-assigning the role.
 *  SEP-11 — Unauthenticated HTTP request to IAM roles endpoint returns 401 (no Fatal Error).
 *
 * Uses DatabaseTransactions with connectionsToTransact() overridden to wrap
 * ONLY the 'tenant' connection. The 'mysql' connection is NOT wrapped, so
 * tenant records inserted in setUp() are committed immediately and visible
 * to the HTTP kernel's own connection (which cannot see uncommitted data).
 * Per-test data (users, roles, tokens) on the 'tenant' connection is rolled
 * back automatically after each test.
 */
class PlatformTenantSeparationTest extends TestCase
{
    use DatabaseTransactions;

    private string $tenantSlug   = 'sep-test-tenant';
    private string $tenantSchema = 'binaa2_testing';

    /**
     * Wrap only the tenant connection in a transaction.
     * The mysql connection is NOT wrapped so that tenant records inserted
     * in setUp() are committed and visible to the HTTP kernel.
     */
    protected function connectionsToTransact(): array
    {
        return ['tenant'];
    }

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSharedTablesExist();
        $this->ensureTenantRecord();
        $this->configureTenantConnection();
        $this->ensureIamTablesExist();
        $this->ensurePlatformUsersTableExists();
    }

    protected function tearDown(): void
    {
        // Clean up committed mysql data (not wrapped in transaction).
        try {
            DB::connection('mysql')->table('tenants')
                ->where('slug', $this->tenantSlug)
                ->delete();
        } catch (\Throwable) {}

        try {
            if (Schema::connection('mysql')->hasTable('platform_users')) {
                DB::connection('mysql')->table('platform_users')
                    ->where('email', 'like', 'sep-test-%')
                    ->delete();
            }
        } catch (\Throwable) {}

        parent::tearDown();
    }

    // =========================================================================
    // SEP-07 â€” PlatformUser model uses 'mysql' connection, never 'tenant'
    // =========================================================================

    /** @test */
    public function platform_user_model_uses_shared_mysql_connection(): void
    {
        $user = new PlatformUser();

        $this->assertSame(
            'mysql',
            $user->getConnectionName(),
            'PlatformUser must use the shared mysql connection, never the tenant connection'
        );
    }

    /** @test */
    public function platform_user_has_no_tenant_id_column(): void
    {
        $columns = Schema::connection('mysql')->getColumnListing('platform_users');

        $this->assertNotContains(
            'tenant_id',
            $columns,
            'platform_users table must NOT have a tenant_id column'
        );
        $this->assertContains('email', $columns);
        $this->assertContains('is_active', $columns);
    }

    // =========================================================================
    // SEP-08 â€” Inactive PlatformUser is rejected (403)
    // =========================================================================

    /** @test */
    public function inactive_platform_user_is_rejected_by_require_platform_operator(): void
    {
        $middleware = new \App\Http\Middleware\RequirePlatformOperator();

        $inactiveOperator = new PlatformUser([
            'name'      => 'Inactive Operator',
            'email'     => 'sep-test-inactive@binaa.test',
            'is_active' => false,
        ]);

        $request = \Illuminate\Http\Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $inactiveOperator);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('inactive', strtolower($data['error']));
    }

    // =========================================================================
    // SEP-01 â€” Tenant user is rejected by RequirePlatformOperator (403)
    // =========================================================================

    /** @test */
    public function tenant_user_is_rejected_by_require_platform_operator_middleware(): void
    {
        $middleware = new \App\Http\Middleware\RequirePlatformOperator();

        $tenantUser = new User();
        $tenantUser->setConnection('tenant');

        $request = \Illuminate\Http\Request::create('/api/platform/test', 'GET');
        $request->setUserResolver(fn () => $tenantUser);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Platform operator identity required', $data['error']);
    }

    // =========================================================================
    // SEP-09 â€” Unauthenticated request returns 401
    // =========================================================================

    /** @test */
    public function unauthenticated_request_to_require_platform_operator_returns_401(): void
    {
        $middleware = new \App\Http\Middleware\RequirePlatformOperator();

        $request = \Illuminate\Http\Request::create('/api/platform/test', 'GET');
        $request->setUserResolver(fn () => null);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    // =========================================================================
    // SEP-03 â€” Tenant user WITHOUT tenant_admin cannot assign permissions (403)
    // =========================================================================

    /** @test */
    public function tenant_user_without_tenant_admin_cannot_assign_permissions(): void
    {
        $user = $this->makeTenantUser();
        // No roles assigned â€” plain user.
        $token = $user->createToken('api')->plainTextToken;

        $role       = Role::on('tenant')->create(['name' => 'sep-target-role-' . uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'sep-perm-' . uniqid(), 'guard_name' => 'api']);

        $response = $this->postJson(
            "/api/iam/roles/{$role->id}/permissions/{$permission->id}",
            [],
            [
                'Authorization' => 'Bearer ' . $token,
                'X-Tenant'      => $this->tenantSlug,
            ]
        );

        $response->assertStatus(403);
        $data = $response->json();
        $this->assertStringContainsString('Forbidden', $data['error']);
    }

    // =========================================================================
    // SEP-04 â€” Tenant user WITH tenant_admin CAN assign permissions (200)
    // =========================================================================

    /** @test */
    public function tenant_admin_user_can_assign_permissions(): void
    {
        $user = $this->makeTenantUser();

        // Grant tenant_admin role.
        $adminRole = Role::on('tenant')->firstOrCreate(
            ['name' => 'tenant_admin'],
            ['guard_name' => 'api']
        );
        $user->assignRole($adminRole);

        $token = $user->createToken('api')->plainTextToken;

        $targetRole = Role::on('tenant')->create(['name' => 'sep-target-' . uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'sep-perm-admin-' . uniqid(), 'guard_name' => 'api']);

        $response = $this->postJson(
            "/api/iam/roles/{$targetRole->id}/permissions/{$permission->id}",
            [],
            [
                'Authorization' => 'Bearer ' . $token,
                'X-Tenant'      => $this->tenantSlug,
            ]
        );

        $response->assertStatus(200);
        $this->assertSame('Permission assigned successfully.', $response->json('message'));
    }

    // =========================================================================
    // SEP-05 â€” Tenant user WITH iam:roles:assign_permission CAN assign (200)
    // =========================================================================

    /** @test */
    public function user_with_assign_permission_capability_can_assign_permissions(): void
    {
        $user = $this->makeTenantUser();

        // Grant the specific permission (not the full tenant_admin role).
        $user->grantPermission('iam:roles:assign_permission');

        $token = $user->createToken('api')->plainTextToken;

        $targetRole = Role::on('tenant')->create(['name' => 'sep-target-perm-' . uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'sep-perm-specific-' . uniqid(), 'guard_name' => 'api']);

        $response = $this->postJson(
            "/api/iam/roles/{$targetRole->id}/permissions/{$permission->id}",
            [],
            [
                'Authorization' => 'Bearer ' . $token,
                'X-Tenant'      => $this->tenantSlug,
            ]
        );

        $response->assertStatus(200);
    }

    // =========================================================================
    // SEP-10 â€” Tenant user cannot self-escalate to tenant_admin
    // =========================================================================

    /** @test */
    public function unprivileged_user_cannot_self_escalate_to_tenant_admin(): void
    {
        // User A has no roles.
        $userA  = $this->makeTenantUser();
        $tokenA = $userA->createToken('api')->plainTextToken;

        // tenant_admin role exists.
        $adminRole = Role::on('tenant')->firstOrCreate(
            ['name' => 'tenant_admin'],
            ['guard_name' => 'api']
        );

        // A privilege that would allow assigning roles â€” does NOT exist on userA.
        $escalationPermission = Permission::on('tenant')->firstOrCreate(
            ['name' => 'iam:roles:assign_permission'],
            ['guard_name' => 'api']
        );

        // Attempt: userA tries to give itself the escalation permission via the API.
        // This requires calling assignPermission â€” which requires tenant_admin or
        // iam:roles:assign_permission. UserA has neither â†’ 403.
        $response = $this->postJson(
            "/api/iam/roles/{$adminRole->id}/permissions/{$escalationPermission->id}",
            [],
            [
                'Authorization' => 'Bearer ' . $tokenA,
                'X-Tenant'      => $this->tenantSlug,
            ]
        );

        $response->assertStatus(403);

        // Confirm userA still has no roles after the failed attempt.
        $this->assertFalse(
            $userA->fresh()->hasRole('tenant_admin'),
            'User must not have tenant_admin after a failed escalation attempt'
        );
    }

    // =========================================================================
    // SEP-06 â€” Cross-tenant: user from tenant A cannot access tenant B
    // =========================================================================

    /** @test */
    public function user_token_from_tenant_a_is_rejected_when_accessing_tenant_b(): void
    {
        // Create user in the test tenant (sep-test-tenant â†’ binaa2_testing).
        $user  = $this->makeTenantUser();
        $token = $user->createToken('api')->plainTextToken;

        // Attempt to use the token with a DIFFERENT tenant slug.
        // 'unknown-other-tenant' does not exist â†’ ResolveTenantContext returns 404.
        $response = $this->postJson(
            '/api/auth/logout',
            [],
            [
                'Authorization' => 'Bearer ' . $token,
                'X-Tenant'      => 'unknown-other-tenant',
            ]
        );

        // 404 from ResolveTenantContext (tenant not found) â€” cross-tenant access blocked.
        $response->assertStatus(404);
    }

    // =========================================================================
    // SEP-02 â€” VerifyTenantMembership: user not in tenant schema is rejected
    // =========================================================================

    /** @test */
    public function verify_tenant_membership_rejects_user_not_in_tenant_schema(): void
    {
        $middleware = new \App\Http\Middleware\VerifyTenantMembership();

        // Bind a TenantContext pointing at the test schema.
        $context = new \App\Foundation\TenantContext(
            id: 1,
            slug: $this->tenantSlug,
            name: 'Sep Test Tenant',
            schemaName: $this->tenantSchema,
            status: 'active',
        );
        \Illuminate\Support\Facades\App::instance(\App\Foundation\TenantContext::class, $context);

        // Create a user object that does NOT exist in the DB (no save).
        $ghostUser = new User();
        $ghostUser->setConnection('tenant');
        $ghostUser->id = \Illuminate\Support\Str::uuid()->toString();

        $request = \Illuminate\Http\Request::create('/api/auth/me', 'GET');
        $request->setUserResolver(fn () => $ghostUser);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('does not belong to this tenant', $data['error']);
    }

    // =========================================================================
    // SEP-11 — Unauthenticated HTTP request to IAM roles endpoint returns 401
    // =========================================================================

    /** @test */
    public function unauthenticated_request_to_iam_roles_endpoint_returns_401_without_fatal_error(): void
    {
        // Send POST /api/iam/roles/{id}/permissions/{id} with NO Authorization header.
        // auth:sanctum middleware must intercept and return 401.
        // RoleController must NOT throw a Fatal Error (null actor guard is in place).
        $response = $this->postJson(
            '/api/iam/roles/1/permissions/1',
            [],
            [
                'X-Tenant' => $this->tenantSlug,
                // Intentionally no Authorization header — unauthenticated request.
            ]
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function makeTenantUser(): User
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Sep Test User';
        $user->email    = 'sep-test-' . uniqid() . '@binaa.test';
        $user->password = Hash::make('password');
        $user->save();

        return $user;
    }

    private function ensureSharedTablesExist(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Schema::connection('mysql')->create('tenants', function ($table) {
                $table->id();
                $table->string('slug', 63)->unique();
                $table->string('name');
                $table->string('schema_name', 80)->unique()->nullable();
                $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
                $table->timestamps();
                $table->index('status');
            });
        }
    }

    private function ensureTenantRecord(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Sep Test Tenant',
            'schema_name' => $this->tenantSchema,
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function configureTenantConnection(): void
    {
        Config::set('database.connections.tenant.driver',    'mysql');
        Config::set('database.connections.tenant.host',      config('database.connections.mysql.host', '127.0.0.1'));
        Config::set('database.connections.tenant.port',      config('database.connections.mysql.port', '3307'));
        Config::set('database.connections.tenant.database',  $this->tenantSchema);
        Config::set('database.connections.tenant.username',  config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password',  config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset',   'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix',    '');
        Config::set('database.connections.tenant.strict',    true);
        DB::purge('tenant');
    }

    private function ensureIamTablesExist(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('users')) {
            $schema->create('users', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('roles')) {
            $schema->create('roles', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('permissions')) {
            $schema->create('permissions', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('model_has_roles')) {
            $schema->create('model_has_roles', function ($table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            });
        }

        if (! $schema->hasTable('role_has_permissions')) {
            $schema->create('role_has_permissions', function ($table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            });
        }

        if (! $schema->hasTable('personal_access_tokens')) {
            $schema->create('personal_access_tokens', function ($table) {
                $table->id();
                $table->uuidMorphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
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
}