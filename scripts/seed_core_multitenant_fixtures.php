<?php

declare(strict_types=1);

/**
 * Seed isolated Core Ecosystem multi-tenant HTTP fixtures on local MySQL 3306.
 * Creates/updates: owner, consultant, contractor tenants + users + roles.
 * NEVER run against Production. Does not print secrets.
 *
 * Usage (from backend/):
 *   php scripts/seed_core_multitenant_fixtures.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\Foundation\TenantProvisioner;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

if (($app['config']->get('app.env')) === 'production') {
    fwrite(STDERR, "REFUSED: production\n");
    exit(1);
}

$host = (string) config('database.connections.mysql.host');
$port = (string) config('database.connections.mysql.port');
$db = (string) config('database.connections.mysql.database');
if ($host !== '127.0.0.1' && $host !== 'localhost') {
    fwrite(STDERR, "REFUSED: non-local mysql host\n");
    exit(1);
}
if (! in_array($port, ['3306', '3307'], true)) {
    fwrite(STDERR, "REFUSED: unexpected mysql port {$port}\n");
    exit(1);
}
if ($db !== 'binaa2_shared' && $db !== 'binaa2_phpunit') {
    fwrite(STDERR, "REFUSED: unexpected shared database {$db}\n");
    exit(1);
}

$password = getenv('BINAA2_FIXTURE_PASSWORD');
if (! is_string($password) || strlen($password) < 12) {
    $password = 'CoreFix#'.Str::random(12).'Aa1';
    putenv('BINAA2_FIXTURE_PASSWORD='.$password);
}

$fixtures = [
    ['slug' => 'core-owner', 'name' => 'Core Owner Org', 'activity_type' => 'owner', 'email' => 'owner@core-fixture.test'],
    ['slug' => 'core-consultant', 'name' => 'Core Consultant Org', 'activity_type' => 'consultant', 'email' => 'consultant@core-fixture.test'],
    ['slug' => 'core-contractor', 'name' => 'Core Contractor Org', 'activity_type' => 'contractor', 'email' => 'contractor@core-fixture.test'],
];

function connectTenantSchema(string $schema): void
{
    $base = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($base, ['database' => $schema])]);
    DB::purge('tenant');
    DB::reconnect('tenant');
}

/** @var TenantProvisioner $provisioner */
$provisioner = app(TenantProvisioner::class);

foreach ($fixtures as $f) {
    $tenant = Tenant::on('mysql')->where('slug', $f['slug'])->first();

    if ($tenant === null) {
        try {
            $tenant = $provisioner->provision($f['slug'], $f['name']);
            echo 'TENANT_PROVISIONED='.$f['slug'].PHP_EOL;
        } catch (Throwable $e) {
            echo 'TENANT_PROVISION_ERR='.$f['slug'].' '.$e->getMessage().PHP_EOL;
            // Fallback: create schema + tenant row with server-derived schema_name
            $schema = Tenant::deriveSchemaName($f['slug']);
            try {
                DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable $createErr) {
                echo 'SCHEMA_CREATE_ERR='.$createErr->getMessage().PHP_EOL;
            }
            $tenant = new Tenant([
                'slug' => $f['slug'],
                'name' => $f['name'],
                'legal_type' => 'organization',
                'activity_type' => $f['activity_type'],
                'status' => 'active',
            ]);
            $tenant->schema_name = $schema;
            $tenant->save();
            echo 'TENANT_FALLBACK_CREATED='.$f['slug'].' schema='.$schema.PHP_EOL;
        }
    } else {
        echo 'TENANT_EXISTS='.$f['slug'].PHP_EOL;
        if (empty($tenant->schema_name)) {
            $tenant->schema_name = Tenant::deriveSchemaName($f['slug']);
            try {
                DB::connection('mysql')->statement(
                    'CREATE DATABASE IF NOT EXISTS `'.$tenant->schema_name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
            } catch (Throwable $createErr) {
                echo 'SCHEMA_CREATE_ERR='.$createErr->getMessage().PHP_EOL;
            }
        }
    }

    $tenant->legal_type = 'organization';
    $tenant->activity_type = $f['activity_type'];
    if ($tenant->status !== 'active') {
        $tenant->status = 'active';
    }
    $tenant->save();

    $tenant = $tenant->fresh();
    if (empty($tenant->schema_name)) {
        echo 'SKIP_USER_NO_SCHEMA='.$f['slug'].PHP_EOL;
        continue;
    }

    connectTenantSchema($tenant->schema_name);

    // Ensure minimal IAM users table exists for fallback-provisioned tenants
    if (! Schema::connection('tenant')->hasTable('users')) {
        echo 'IAM_MISSING='.$f['slug'].' — copying from auth-smoke if possible'.PHP_EOL;
        $donor = 'binaa2_tenant_auth_smoke';
        try {
            $tables = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'personal_access_tokens'];
            foreach ($tables as $table) {
                DB::connection('mysql')->statement(
                    "CREATE TABLE IF NOT EXISTS `{$tenant->schema_name}`.`{$table}` LIKE `{$donor}`.`{$table}`"
                );
            }
            echo 'IAM_COPIED_STRUCTURE='.$f['slug'].PHP_EOL;
        } catch (Throwable $copyErr) {
            echo 'IAM_COPY_ERR='.$copyErr->getMessage().PHP_EOL;
            continue;
        }
    }

    $user = User::on('tenant')->where('email', $f['email'])->first();
    if ($user === null) {
        $user = new User;
        $user->setConnection('tenant');
        $user->name = $f['name'].' Admin';
        $user->email = $f['email'];
        $user->password = Hash::make($password);
        if (Schema::connection('tenant')->hasColumn('users', 'tenant_slug')) {
            $user->tenant_slug = $f['slug'];
        }
        $user->save();
        echo 'USER_CREATED='.$f['email'].PHP_EOL;
    } else {
        $user->password = Hash::make($password);
        $user->save();
        echo 'USER_UPDATED='.$f['email'].PHP_EOL;
    }

    if (Schema::connection('tenant')->hasTable('roles')) {
        $role = Role::on('tenant')->where('name', 'tenant_admin')->first();
        if ($role && method_exists($user, 'assignRole') && ! $user->hasRole('tenant_admin')) {
            $user->assignRole($role);
            echo 'ROLE_ASSIGNED='.$f['slug'].PHP_EOL;
        }
    }
}

// Also classify existing auth-smoke pair for cross-tenant negative probes
$authSmoke = Tenant::on('mysql')->where('slug', 'auth-smoke')->first();
if ($authSmoke) {
    $authSmoke->legal_type = $authSmoke->legal_type ?: 'organization';
    $authSmoke->activity_type = $authSmoke->activity_type ?: 'contractor';
    $authSmoke->save();
    echo 'AUTH_SMOKE_CLASSIFIED=contractor'.PHP_EOL;
}
$authSmokeB = Tenant::on('mysql')->where('slug', 'auth-smoke-b')->first();
if ($authSmokeB) {
    $authSmokeB->legal_type = $authSmokeB->legal_type ?: 'organization';
    if (! $authSmokeB->activity_type) {
        $authSmokeB->activity_type = 'consultant';
    }
    $authSmokeB->save();
    echo 'AUTH_SMOKE_B_CLASSIFIED='.$authSmokeB->activity_type.PHP_EOL;
}

$out = [
    'password_len' => strlen($password),
    'tenants' => array_column($fixtures, 'slug'),
    'emails' => array_column($fixtures, 'email'),
    'shared_db' => $db,
    'port' => $port,
];
echo 'FIXTURES_OK='.json_encode($out).PHP_EOL;
echo 'PASSWORD_SET_IN_ENV=BINAA2_FIXTURE_PASSWORD (not printed)'.PHP_EOL;
