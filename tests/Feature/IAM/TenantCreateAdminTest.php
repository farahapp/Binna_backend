<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for the tenant:create-admin Artisan command.
 *
 * All tests run against the isolated test database on port 3307
 * (phpunit.xml: DB_PORT=3307, TENANT_DB_PORT=3307, DB_DATABASE=binaa2_testing).
 *
 * No real tenant is provisioned, no schema is created or dropped,
 * and no MySQL settings are altered. The tenant record uses
 * schema_name='binaa2_testing' so the command operates on the
 * existing test database without DDL.
 *
 * Coverage (SPEC-FA-001 / GAP-2):
 *   TC-CA-01  Missing / empty --slug → FAILURE
 *   TC-CA-02  Inactive (pending) tenant → FAILURE
 *   TC-CA-03  Idempotency: user already exists → FAILURE
 *   TC-CA-04  Happy path: user created, 'company-admin' role assigned → SUCCESS
 *   TC-CA-05  Production env without --confirm → FAILURE
 *   TC-CA-06  Production env with --confirm → SUCCESS
 *
 * Constraints:
 *   - No application or existing test files modified.
 *   - No real tenant provisioned; no schema created/dropped.
 *   - No MySQL settings altered.
 *   - No stage/commit/push/deploy.
 */
class TenantCreateAdminTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Slugs / emails used in this test class — unique to avoid cross-test leaks
    // -------------------------------------------------------------------------

    private const ACTIVE_SLUG    = 'ca-test-active';
    private const INACTIVE_SLUG  = 'ca-test-inactive';
    private const SCHEMA         = 'binaa2_testing';
    /** Fictitious schema name for the inactive tenant — never connected to. */
    private const INACTIVE_SCHEMA = 'binaa2_testing_ca_inactive';

    private const ADMIN_EMAIL   = 'ca-admin@example.com';
    private const ADMIN_NAME    = 'CA Admin User';

    /** Minimum password length enforced by the command. */
    private const MIN_PW_LEN    = 12;

    // -------------------------------------------------------------------------
    // setUp / tearDown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSharedTablesExist();
        $this->insertTenantRecords();
        $this->configureTenantConnection();
        $this->ensureIamTablesExist();
    }

    protected function tearDown(): void
    {
        $this->cleanupTenantData();
        $this->cleanupMysqlData();
        parent::tearDown();
    }

    // =========================================================================
    // TC-CA-01 — Missing / empty --slug → FAILURE
    // =========================================================================

    /** @test */
    public function it_fails_when_slug_is_missing(): void
    {
        $this->artisan('tenant:create-admin', [
            '--email' => self::ADMIN_EMAIL,
            '--name'  => self::ADMIN_NAME,
        ])
            ->assertFailed()
            ->expectsOutputToContain('--slug is required');
    }

    /** @test */
    public function it_fails_when_slug_is_empty_string(): void
    {
        $this->artisan('tenant:create-admin', [
            '--slug'  => '',
            '--email' => self::ADMIN_EMAIL,
            '--name'  => self::ADMIN_NAME,
        ])
            ->assertFailed()
            ->expectsOutputToContain('--slug is required');
    }

    // =========================================================================
    // TC-CA-02 — Inactive (pending) tenant → FAILURE
    // =========================================================================

    /** @test */
    public function it_fails_when_tenant_is_not_active(): void
    {
        $this->artisan('tenant:create-admin', [
            '--slug'  => self::INACTIVE_SLUG,
            '--email' => self::ADMIN_EMAIL,
            '--name'  => self::ADMIN_NAME,
        ])
            ->assertFailed()
            ->expectsOutputToContain('is not active');
    }

    // =========================================================================
    // TC-CA-03 — Idempotency: user already exists → FAILURE
    // =========================================================================

    /** @test */
    public function it_fails_when_user_already_exists_in_tenant(): void
    {
        // Pre-create the user directly in the tenant schema.
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Pre-existing Admin';
        $user->email    = self::ADMIN_EMAIL;
        $user->password = Hash::make('pre-existing-password-123');
        $user->save();

        $this->artisan('tenant:create-admin', [
            '--slug'  => self::ACTIVE_SLUG,
            '--email' => self::ADMIN_EMAIL,
            '--name'  => self::ADMIN_NAME,
        ])
            ->assertFailed()
            ->expectsOutputToContain('already exists');
    }

    // =========================================================================
    // TC-CA-04 — Happy path: user created, 'company-admin' role assigned
    // =========================================================================

    /** @test */
    public function it_creates_user_and_assigns_company_admin_role(): void
    {
        $password = str_repeat('Aa1!', self::MIN_PW_LEN); // 48 chars, well above minimum

        $this->artisan('tenant:create-admin', [
            '--slug'  => self::ACTIVE_SLUG,
            '--email' => self::ADMIN_EMAIL,
            '--name'  => self::ADMIN_NAME,
        ])
            ->expectsQuestion(
                'Enter password for [' . self::ADMIN_EMAIL . '] (min ' . self::MIN_PW_LEN . ' chars):',
                $password
            )
            ->expectsQuestion('Confirm password:', $password)
            ->assertSuccessful()
            ->expectsOutputToContain('company-admin');

        // Verify user exists in tenant schema.
        $user = User::on('tenant')->where('email', self::ADMIN_EMAIL)->first();
        $this->assertNotNull($user, 'User must exist in tenant schema after command');
        $this->assertSame(self::ADMIN_NAME, $user->name);

        // Verify password is hashed, not plaintext.
        $this->assertTrue(
            Hash::check($password, $user->password),
            'Stored password must verify against the supplied password'
        );
        $this->assertNotSame($password, $user->password, 'Password must not be stored as plaintext');

        // Verify 'company-admin' role exists and is assigned to the user.
        $role = Role::on('tenant')->where('name', 'company-admin')->first();
        $this->assertNotNull($role, "'company-admin' role must exist in tenant schema");
        $this->assertSame('api', $role->guard_name);
        $this->assertTrue(
            $user->hasRole('company-admin'),
            "User must have the 'company-admin' role"
        );
    }

    // =========================================================================
    // TC-CA-05 — Production env without --confirm → FAILURE
    // =========================================================================

    /** @test */
    public function it_refuses_to_run_in_production_without_confirm_flag(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('tenant:create-admin', [
                '--slug'  => self::ACTIVE_SLUG,
                '--email' => self::ADMIN_EMAIL,
                '--name'  => self::ADMIN_NAME,
                // --confirm intentionally omitted
            ])
                ->assertFailed()
                ->expectsOutputToContain('--confirm');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    // =========================================================================
    // TC-CA-06 — Production env with --confirm → SUCCESS
    // =========================================================================

    /** @test */
    public function it_succeeds_in_production_when_confirm_flag_is_passed(): void
    {
        $password = str_repeat('Bb2@', self::MIN_PW_LEN); // 48 chars

        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('tenant:create-admin', [
                '--slug'    => self::ACTIVE_SLUG,
                '--email'   => self::ADMIN_EMAIL,
                '--name'    => self::ADMIN_NAME,
                '--confirm' => true,
            ])
                ->expectsQuestion(
                    'Enter password for [' . self::ADMIN_EMAIL . '] (min ' . self::MIN_PW_LEN . ' chars):',
                    $password
                )
                ->expectsQuestion('Confirm password:', $password)
                ->assertSuccessful()
                ->expectsOutputToContain('company-admin');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }

        // Verify user was created.
        $user = User::on('tenant')->where('email', self::ADMIN_EMAIL)->first();
        $this->assertNotNull($user, 'User must exist after production run with --confirm');
        $this->assertTrue($user->hasRole('company-admin'));
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function ensureSharedTablesExist(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/shared',
                '--force' => true,
            ]);
        }
    }

    private function insertTenantRecords(): void
    {
        // Step 1: Remove any rows this Suite previously inserted (by slug).
        DB::connection('mysql')->table('tenants')
            ->whereIn('slug', [self::ACTIVE_SLUG, self::INACTIVE_SLUG])
            ->delete();

        // Step 2: Guard — detect a foreign stale row sharing our ACTIVE schema_name.
        // INACTIVE_SCHEMA is fictitious and never connected to, so no guard needed there.
        $conflict = DB::connection('mysql')->table('tenants')
            ->where('schema_name', self::SCHEMA)
            ->whereNotIn('slug', [self::ACTIVE_SLUG, self::INACTIVE_SLUG])
            ->first();

        if ($conflict !== null) {
            $this->fail(
                "FIX-A-CODE guard: schema_name='" . self::SCHEMA . "' is already " .
                "claimed by slug='{$conflict->slug}' (id={$conflict->id}). " .
                "A previous test run left a stale row. " .
                "Run: DELETE FROM tenants WHERE id={$conflict->id} AND slug='{$conflict->slug}';"
            );
        }

        // Step 3: Insert our own rows.
        DB::connection('mysql')->table('tenants')->insert([
            'slug'        => self::ACTIVE_SLUG,
            'name'        => 'CA Test Active Tenant',
            'schema_name' => self::SCHEMA,
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::connection('mysql')->table('tenants')->insert([
            'slug'        => self::INACTIVE_SLUG,
            'name'        => 'CA Test Inactive Tenant',
            'schema_name' => self::INACTIVE_SCHEMA,
            'status'      => 'pending',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function configureTenantConnection(): void
    {
        Config::set('database.connections.tenant.driver',    'mysql');
        Config::set('database.connections.tenant.host',      config('database.connections.mysql.host', '127.0.0.1'));
        Config::set('database.connections.tenant.port',      config('database.connections.mysql.port', '3307'));
        Config::set('database.connections.tenant.database',  self::SCHEMA);
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
            });
        }

        if (! $schema->hasTable('role_has_permissions')) {
            $schema->create('role_has_permissions', function ($table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }
    }

    private function cleanupTenantData(): void
    {
        try {
            DB::connection('tenant')->table('model_has_roles')->delete();
            DB::connection('tenant')->table('role_has_permissions')->delete();
            DB::connection('tenant')->table('roles')
                ->where('name', 'company-admin')
                ->delete();
            DB::connection('tenant')->table('users')
                ->where('email', self::ADMIN_EMAIL)
                ->delete();
        } catch (\Throwable) {
            // Ignore — tables may not exist or connection may be unavailable.
        }
    }

    private function cleanupMysqlData(): void
    {
        try {
            DB::connection('mysql')->table('tenants')
                ->whereIn('slug', [self::ACTIVE_SLUG, self::INACTIVE_SLUG])
                ->delete();
        } catch (\Throwable) {
            // Ignore.
        }
    }
}
