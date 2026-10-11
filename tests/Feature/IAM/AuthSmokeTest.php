<?php

/**
 * AuthSmokeTest.php
 *
 * Feature test: full authentication smoke test for the BINAA2 multi-tenant IAM.
 *
 * Tests:
 *  1. Programmatic verification: tenant/schema/tables/user/hash/role/permission.
 *  2. POST /api/auth/login — real HTTP with X-Tenant header.
 *  3. PAT (Personal Access Token) issued and usable.
 *  4. Protected endpoint accessible with valid token.
 *  5. 401 returned without token.
 *  6. Tenant context isolation: wrong tenant slug returns 404.
 *  7. Authorization: user has correct role/permission.
 *
 * CONSTRAINTS:
 *  - No production code modified.
 *  - TenantProvisioner used as-is (not modified).
 *  - IAM migrations orchestrated only in test bootstrap.
 *  - Password not printed or stored in production config.
 *  - No destructive endpoints used.
 */

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Foundation\Tenant;
use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthSmokeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Bootstrap state (shared across tests in this class)
    // -------------------------------------------------------------------------

    private static bool  $bootstrapped = false;
    private static array $ctx          = [];

    // Password is generated once per test run and never printed/stored.
    private static string $password = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            // Generate a random password for this test run only.
            self::$password = bin2hex(random_bytes(16));

            require_once __DIR__ . '/../../bootstrap_iam_tenant.php';

            self::$ctx          = bootstrapAuthSmokeTenant(self::$password);
            self::$bootstrapped = true;
        }
    }

    protected function tearDown(): void
    {
        // Cleanup is deferred to tearDownAfterClass to avoid re-running per test.
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        // Safe cleanup after all tests in this class have run.
        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        cleanupAuthSmokeTenant();

        self::$bootstrapped = false;
        self::$ctx          = [];
        self::$password     = '';

        parent::tearDownAfterClass();
    }

    // =========================================================================
    // 1. Programmatic verification
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function tenant_is_active_and_schema_exists(): void
    {
        $tenant = self::$ctx['tenant'];

        $this->assertEquals('active', $tenant->status, 'Tenant must be active');
        $this->assertEquals('auth-smoke', $tenant->slug, 'Tenant slug must match');
        $this->assertEquals('binaa2_tenant_auth_smoke', $tenant->schema_name, 'Schema name must match');

        // Verify tenant record in shared schema.
        $row = DB::connection('mysql')
            ->table('tenants')
            ->where('slug', 'auth-smoke')
            ->first();

        $this->assertNotNull($row, 'Tenant row must exist in shared schema');
        $this->assertEquals('active', $row->status, 'Tenant status in DB must be active');
        $this->assertEquals('binaa2_tenant_auth_smoke', $row->schema_name, 'Schema name in DB must match');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function required_iam_tables_exist_in_tenant_schema(): void
    {
        $schemaName = self::$ctx['schemaName'];

        $requiredTables = [
            'users',
            'roles',
            'permissions',
            'model_has_roles',
            'role_has_permissions',
            'personal_access_tokens',
            'audit_entries',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::connection('tenant')->hasTable($table),
                "Table [{$table}] must exist in tenant schema [{$schemaName}]"
            );
        }
    }

    /** @test */
    public function user_exists_in_tenant_schema_with_correct_attributes(): void
    {
        $user = self::$ctx['user'];

        // Re-switch tenant connection to the smoke schema (may have been reset by HTTP tests).
        \switchTenantConnection(self::$ctx['schemaName']);

        // Verify user is in tenant schema (not shared schema).
        $found = User::on('tenant')->where('email', 'auth-smoke@binaa.test')->first();

        $this->assertNotNull($found, 'User must exist in tenant schema');
        $this->assertEquals('auth-smoke@binaa.test', $found->email, 'Email must match');
        $this->assertEquals('Auth Smoke User', $found->name, 'Name must match');

        // Verify UUID format.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $found->id,
            'User ID must be a valid UUID'
        );
    }

    /** @test */
    public function password_is_hashed_not_plaintext(): void
    {
        \switchTenantConnection(self::$ctx['schemaName']);
        $user = User::on('tenant')->where('email', 'auth-smoke@binaa.test')->first();

        $this->assertNotNull($user, 'User must exist');

        // Password must be a bcrypt hash, not plaintext.
        $this->assertTrue(
            Hash::check(self::$password, $user->password),
            'Hash::check must verify the password'
        );

        // The stored value must NOT equal the plaintext password.
        $this->assertNotEquals(
            self::$password,
            $user->password,
            'Password must not be stored as plaintext'
        );

        // Must start with bcrypt prefix.
        $this->assertStringStartsWith('$2y$', $user->password, 'Password must be bcrypt hashed');
    }

    /** @test */
    public function user_has_correct_role_and_permission(): void
    {
        \switchTenantConnection(self::$ctx['schemaName']);
        $user = User::on('tenant')->where('email', 'auth-smoke@binaa.test')->first();

        $this->assertNotNull($user, 'User must exist');
        $this->assertTrue($user->hasRole('smoke-viewer'), 'User must have smoke-viewer role');
        $this->assertTrue($user->hasPermission('smoke.view'), 'User must have smoke.view permission');
    }

    /** @test */
    public function role_and_permission_exist_in_tenant_schema(): void
    {
        \switchTenantConnection(self::$ctx['schemaName']);
        $role = Role::on('tenant')->where('name', 'smoke-viewer')->first();
        $this->assertNotNull($role, 'Role smoke-viewer must exist in tenant schema');

        $permission = Permission::on('tenant')->where('name', 'smoke.view')->first();
        $this->assertNotNull($permission, 'Permission smoke.view must exist in tenant schema');

        // Verify role has the permission.
        $this->assertTrue(
            $role->permissions()->where('name', 'smoke.view')->exists(),
            'Role must have smoke.view permission attached'
        );
    }

    // =========================================================================
    // 2. POST /api/auth/login — real HTTP
    // =========================================================================

    /** @test */
    public function login_returns_token_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => self::$password,
        ], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type']);
        $response->assertJsonPath('token_type', 'Bearer');

        // Token must be a non-empty string.
        $token = $response->json('token');
        $this->assertIsString($token, 'Token must be a string');
        $this->assertNotEmpty($token, 'Token must not be empty');
    }

    /** @test */
    public function login_returns_422_with_wrong_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => 'definitely-wrong-password-xyz',
        ], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function login_returns_422_with_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'nobody@binaa.test',
            'password' => self::$password,
        ], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // 3. PAT — Personal Access Token
    // =========================================================================

    /** @test */
    public function pat_is_issued_and_stored_in_tenant_schema(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => self::$password,
        ], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $response->assertStatus(200);
        $token = $response->json('token');

        // Verify PAT is stored in the tenant schema (not shared schema).
        // After the HTTP request, SwitchToTenantSchema has set tenant connection to auth-smoke schema.
        \switchTenantConnection(self::$ctx['schemaName']);
        $patCount = DB::connection('tenant')
            ->table('personal_access_tokens')
            ->count();

        $this->assertGreaterThan(0, $patCount, 'PAT must be stored in tenant schema');
    }

    // =========================================================================
    // 4. Protected endpoint accessible with valid token
    // =========================================================================

    /** @test */
    public function protected_endpoint_accessible_with_valid_token(): void
    {
        // Login to get a token.
        $loginResponse = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => self::$password,
        ], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Use the token to access a protected, non-destructive endpoint.
        // POST /api/auth/logout is protected by auth:sanctum and is non-destructive
        // in the sense that it only revokes the current token.
        $response = $this->postJson('/api/auth/logout', [], [
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant'      => 'auth-smoke',
        ]);

        $response->assertStatus(200);
    }

    // =========================================================================
    // 5. 401 without token
    // =========================================================================

    /** @test */
    public function protected_endpoint_returns_401_without_token(): void
    {
        $response = $this->postJson('/api/auth/logout', [], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // 6. Tenant context isolation — wrong tenant slug
    // =========================================================================

    /** @test */
    public function login_returns_404_for_unknown_tenant(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => self::$password,
        ], [
            'X-Tenant' => 'nonexistent-tenant-xyz',
        ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function login_returns_400_without_x_tenant_header(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => self::$password,
        ]);

        $response->assertStatus(400);
    }

    // =========================================================================
    // 7. Tenant isolation — token from one tenant cannot be used in another
    // =========================================================================

    /** @test */
    public function token_from_correct_tenant_cannot_be_used_in_wrong_tenant(): void
    {
        // Login to get a token for auth-smoke tenant.
        $loginResponse = $this->postJson('/api/auth/login', [
            'email'    => 'auth-smoke@binaa.test',
            'password' => self::$password,
        ], [
            'X-Tenant' => 'auth-smoke',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Try to use the token with a different (nonexistent) tenant.
        // The middleware should reject with 404 (tenant not found) before
        // even reaching auth:sanctum.
        $response = $this->postJson('/api/auth/logout', [], [
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant'      => 'nonexistent-tenant-xyz',
        ]);

        // 404 because the tenant doesn't exist — token is never evaluated.
        $response->assertStatus(404);
    }
}
