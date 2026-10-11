<?php

/**
 * bootstrap_iam_tenant.php
 *
 * Reusable test bootstrap helper: provisions the 'auth-smoke' tenant via
 * TenantProvisioner, then orchestrates IAM + Audit migrations on the
 * tenant connection — all within test infrastructure only.
 *
 * CONSTRAINTS:
 *  - Does NOT modify production code, TenantProvisioner, auth/Sanctum, or business logic.
 *  - Does NOT print or persist passwords/tokens in production config.
 *  - IAM migrations are applied only on the tenant connection (test-only).
 *  - Cleanup is safe: removes only test-created rows by slug.
 *
 * Usage: require this file from a test setUp() or a standalone script.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Foundation\TenantProvisioner;
use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

// Use define() instead of const to avoid "already defined" errors when file is required multiple times.
if (! defined('SMOKE_SLUG')) {
    define('SMOKE_SLUG',       'auth-smoke');
    define('SMOKE_EMAIL',      'auth-smoke@binaa.test');
    define('SMOKE_ROLE',       'smoke-viewer');
    define('SMOKE_PERMISSION', 'smoke.view');
}

/**
 * Bootstrap the auth-smoke tenant:
 *  1. Provision via TenantProvisioner (creates schema + baseline).
 *  2. Switch tenant connection to the new schema.
 *  3. Run IAM + Audit migrations on the tenant connection.
 *  4. Create User, Role, Permission and assign them.
 *
 * Returns array with keys: tenant, user, role, permission, schemaName.
 * Password is generated locally and NOT stored anywhere outside this scope.
 */
function bootstrapAuthSmokeTenant(string $password): array
{
    $app = app();

    // -------------------------------------------------------------------------
    // Step 1: Clean up any leftover from a previous run before provisioning.
    // -------------------------------------------------------------------------
    cleanupAuthSmokeTenant();

    // -------------------------------------------------------------------------
    // Step 2: Provision tenant via TenantProvisioner (no modification to it).
    // If the tenant already exists (cleanup failed), reuse it.
    // -------------------------------------------------------------------------
    /** @var TenantProvisioner $provisioner */
    $provisioner = $app->make(TenantProvisioner::class);

    $existingTenant = \App\Foundation\Tenant::where('slug', SMOKE_SLUG)->first();
    if ($existingTenant) {
        $tenant = $existingTenant;
    } else {
        $tenant = $provisioner->provision(SMOKE_SLUG, 'Auth Smoke Test Tenant');
    }

    assert($tenant->status === 'active', 'Tenant must be active after provisioning');
    assert($tenant->schema_name === 'binaa2_tenant_auth_smoke', 'Schema name must match');

    $schemaName = $tenant->schema_name;

    // -------------------------------------------------------------------------
    // Step 2: Switch tenant connection to the provisioned schema
    // -------------------------------------------------------------------------
    switchTenantConnection($schemaName);

    // -------------------------------------------------------------------------
    // Step 3: Run IAM + Audit migrations on the tenant connection
    //         (test infrastructure only — not production code)
    // -------------------------------------------------------------------------
    runIamMigrationsOnTenantConnection();

    // -------------------------------------------------------------------------
    // Step 4: Verify tables exist
    // -------------------------------------------------------------------------
    $requiredTables = ['users', 'roles', 'permissions', 'model_has_roles', 'role_has_permissions', 'personal_access_tokens', 'audit_entries'];
    foreach ($requiredTables as $table) {
        assert(
            Schema::connection('tenant')->hasTable($table),
            "Required table [{$table}] missing from tenant schema [{$schemaName}]"
        );
    }

    // -------------------------------------------------------------------------
    // Step 5: Create User with hashed password (password not printed/stored)
    // Use firstOrCreate to be idempotent — if user already exists (e.g. from
    // a previous run where cleanup failed), update the password.
    // -------------------------------------------------------------------------
    $existingUser = User::on('tenant')->where('email', SMOKE_EMAIL)->first();
    if ($existingUser) {
        // User exists from a previous run — update password and reuse.
        $existingUser->password = Hash::make($password);
        $existingUser->save();
        $user = $existingUser;
    } else {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Auth Smoke User';
        $user->email    = SMOKE_EMAIL;
        $user->password = Hash::make($password);
        $user->save();
    }

    assert($user->id !== null, 'User must have an ID after save');
    assert(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user->id) === 1, 'User ID must be a UUID');

    // Verify password hash is correct (bcrypt, not plaintext)
    assert(Hash::check($password, $user->password), 'Password hash must verify correctly');
    assert(! str_starts_with($user->password, $password), 'Password must not be stored as plaintext');

    // -------------------------------------------------------------------------
    // Step 6: Create minimal Role + Permission and assign (idempotent)
    // -------------------------------------------------------------------------
    $permission = Permission::on('tenant')->firstOrCreate(
        ['name' => SMOKE_PERMISSION],
        ['guard_name' => 'api']
    );

    $role = Role::on('tenant')->firstOrCreate(
        ['name' => SMOKE_ROLE],
        ['guard_name' => 'api']
    );

    // Attach permission to role only if not already attached.
    if (! $role->permissions()->where('name', SMOKE_PERMISSION)->exists()) {
        $role->givePermission($permission);
    }

    // Attach role to user only if not already attached.
    if (! $user->hasRole(SMOKE_ROLE)) {
        $user->assignRole($role);
    }

    // Verify role assignment
    assert($user->hasRole(SMOKE_ROLE), 'User must have the smoke role');
    assert($user->hasPermission(SMOKE_PERMISSION), 'User must have the smoke permission');

    return compact('tenant', 'user', 'role', 'permission', 'schemaName');
}

/**
 * Switch the 'tenant' Eloquent connection to point at the given schema.
 * This is test-only infrastructure — mirrors what SwitchToTenantSchema
 * middleware does at runtime, but without touching production code.
 */
function switchTenantConnection(string $schemaName): void
{
    $base = Config::get('database.connections.mysql');

    Config::set('database.connections.tenant', array_merge($base, [
        'database' => $schemaName,
    ]));

    DB::purge('tenant');
    DB::reconnect('tenant');
}

/**
 * Run IAM and Audit migrations on the current 'tenant' connection.
 * Uses Laravel's Migrator directly — test infrastructure only.
 * Creates the migrations tracking table if it doesn't exist.
 */
function runIamMigrationsOnTenantConnection(): void
{
    $schema = Schema::connection('tenant');

    // Ensure the migrations tracking table exists in the tenant schema.
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

    // Run IAM migrations (users, roles, permissions, pivot tables, PATs).
    $migrator->run(database_path('migrations/iam'));

    // Run Audit migrations (audit_entries).
    $migrator->run(database_path('migrations/audit'));

    // CRITICAL: Reset the migrator connection back to the default (mysql/shared)
    // so that subsequent RefreshDatabase calls in other tests do not accidentally
    // run migrate:fresh against the tenant schema instead of binaa2_testing.
    // This was the root cause of the "missing migrations table" conflict recorded
    // in the MEP (2026-10-10): $migrator->setConnection('tenant') mutates the
    // shared migrator singleton, leaking the tenant connection into later tests.
    $migrator->setConnection(null);
}

/**
 * Safe cleanup: removes only test-created rows by slug.
 * Does NOT drop the schema — leaves it for inspection if needed.
 *
 * IMPORTANT: Each delete is wrapped in its own try-catch so that a failure
 * on any one step (e.g. tenant_migrations table not found) does NOT prevent
 * subsequent steps from running. In particular, the tenants row MUST always
 * be deleted to prevent UniqueConstraintViolationException on the next run.
 */
function cleanupAuthSmokeTenant(): void
{
    try {
        // Find the tenant record to get schema_name.
        $tenant = DB::connection('mysql')
            ->table('tenants')
            ->where('slug', SMOKE_SLUG)
            ->first();

        if ($tenant) {
            $schemaName = $tenant->schema_name;

            // Switch to tenant schema and clean IAM data (best-effort).
            try {
                switchTenantConnection($schemaName);

                DB::connection('tenant')->table('personal_access_tokens')->delete();
                DB::connection('tenant')->table('model_has_roles')->delete();
                DB::connection('tenant')->table('role_has_permissions')->delete();
                DB::connection('tenant')->table('users')->where('email', SMOKE_EMAIL)->delete();
                DB::connection('tenant')->table('roles')->where('name', SMOKE_ROLE)->delete();
                DB::connection('tenant')->table('permissions')->where('name', SMOKE_PERMISSION)->delete();
                DB::connection('tenant')->table('audit_entries')->delete();
            } catch (\Throwable) {
                // Schema may not have IAM tables yet — ignore.
            }

            // Remove shared-schema records. Each step is isolated so that a
            // failure in tenant_migrations does NOT prevent tenant row deletion.
            // Note: 'mysql' connection points to DB_DATABASE (binaa2_testing in tests).
            try {
                DB::connection('mysql')->table('tenant_migrations')->where('tenant_id', $tenant->id)->delete();
            } catch (\Throwable) {
                // tenant_migrations table may not exist in some test contexts — ignore.
            }

            // CRITICAL: This must always run. If this is skipped, the next
            // bootstrapAuthSmokeTenant() call will fail with UniqueConstraintViolationException.
            DB::connection('mysql')->table('tenants')->where('slug', SMOKE_SLUG)->delete();
        }
    } catch (\Throwable) {
        // Cleanup is best-effort.
    }
}
