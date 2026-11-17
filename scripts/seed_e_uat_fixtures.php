<?php

declare(strict_types=1);

/**
 * Wave E — local UAT fixture refresh (NOT Production).
 * Builds on D.5 core-* tenants + platform-smoke.
 * Writes credentials to artifacts/playwright/BINAA_QATAR_E/.e-uat-local.env
 *
 * Usage:
 *   php scripts/seed_d5_visual_fixtures.php
 *   php scripts/ensure_d5_tenant_admins.php
 *   php scripts/seed_e_uat_fixtures.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

if (($app['config']->get('app.env')) === 'production') {
    fwrite(STDERR, "REFUSED: production\n");
    exit(1);
}

$host = (string) config('database.connections.mysql.host');
$db = (string) config('database.connections.mysql.database');
if ($host !== '127.0.0.1' && $host !== 'localhost') {
    fwrite(STDERR, "REFUSED: non-local mysql host\n");
    exit(1);
}
if (! in_array($db, ['binaa2_shared', 'binaa2_phpunit', 'binaa2_testing'], true)) {
    fwrite(STDERR, "REFUSED: unexpected database {$db}\n");
    exit(1);
}

$password = getenv('BINAA2_E_UAT_PASSWORD');
if (! is_string($password) || strlen($password) < 12) {
    $password = 'EUat#Local2026Aa1';
}

passthru(PHP_BINARY.' '.escapeshellarg(__DIR__.'/seed_d5_visual_fixtures.php'), $d5Code);
putenv('BINAA2_D5_PASSWORD='.$password);
passthru(PHP_BINARY.' '.escapeshellarg(__DIR__.'/ensure_d5_tenant_admins.php'), $adminCode);

// Extra employee-style login user under contractor (non-admin)
$contractor = Tenant::on('mysql')->where('slug', 'core-contractor')->first();
if ($contractor && $contractor->schema_name) {
    config(['database.connections.tenant' => array_merge(
        config('database.connections.mysql'),
        ['database' => $contractor->schema_name]
    )]);
    DB::purge('tenant');
    DB::reconnect('tenant');
    if (Schema::connection('tenant')->hasTable('users')) {
        $email = 'employee@core-fixture.test';
        $user = User::on('tenant')->where('email', $email)->first();
        if ($user === null) {
            $user = new User;
            $user->setConnection('tenant');
            $user->name = 'Contractor Employee';
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->save();
            echo 'EMPLOYEE_CREATED='.$email.PHP_EOL;
        } else {
            $user->password = Hash::make($password);
            $user->save();
            echo 'EMPLOYEE_UPDATED='.$email.PHP_EOL;
        }
        // Ensure a non-admin role exists for clarity
        if (Schema::connection('tenant')->hasTable('roles')) {
            Role::on('tenant')->firstOrCreate(['name' => 'employee'], ['guard_name' => 'api']);
        }
    }
}

$plat = PlatformUser::on('mysql')->where('email', 'platform-smoke@binaa.test')->first();
if ($plat) {
    $plat->password = Hash::make($password);
    $plat->is_active = true;
    $plat->save();
    echo 'PLATFORM_OK=platform-smoke@binaa.test'.PHP_EOL;
}

// Ensure system platform-owner role exists, has all platform.* permissions, and is linked to UAT operator.
// Local shared DB historically had permissions seeded without any platform_roles rows.
$now = now();
$ownerRoleId = DB::connection('mysql')->table('platform_roles')->where('slug', 'platform-owner')->value('id');
if ($ownerRoleId === null) {
    $ownerRoleId = DB::connection('mysql')->table('platform_roles')->insertGetId([
        'name' => 'Platform Owner',
        'slug' => 'platform-owner',
        'description' => 'Full local platform operator role for UAT',
        'is_system' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo 'PLATFORM_ROLE_CREATED=platform-owner'.PHP_EOL;
} else {
    echo 'PLATFORM_ROLE_OK=platform-owner'.PHP_EOL;
}

$permissionIds = DB::connection('mysql')->table('platform_permissions')->pluck('id');
$linked = 0;
foreach ($permissionIds as $permissionId) {
    $exists = DB::connection('mysql')->table('platform_role_permissions')
        ->where('platform_role_id', $ownerRoleId)
        ->where('platform_permission_id', $permissionId)
        ->exists();
    if (! $exists) {
        DB::connection('mysql')->table('platform_role_permissions')->insert([
            'platform_role_id' => $ownerRoleId,
            'platform_permission_id' => $permissionId,
        ]);
        $linked++;
    }
}
echo 'PLATFORM_ROLE_PERMS_LINKED='.$linked.PHP_EOL;

if ($plat) {
    $hasRole = DB::connection('mysql')->table('platform_user_roles')
        ->where('platform_user_id', $plat->id)
        ->where('platform_role_id', $ownerRoleId)
        ->exists();
    if (! $hasRole) {
        DB::connection('mysql')->table('platform_user_roles')->insert([
            'platform_user_id' => $plat->id,
            'platform_role_id' => $ownerRoleId,
        ]);
        echo 'PLATFORM_USER_ROLE_ASSIGNED=platform-smoke@binaa.test'.PHP_EOL;
    } else {
        echo 'PLATFORM_USER_ROLE_OK=platform-smoke@binaa.test'.PHP_EOL;
    }
}

$credDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'artifacts'.DIRECTORY_SEPARATOR.'playwright'.DIRECTORY_SEPARATOR.'BINAA_QATAR_E';
@mkdir($credDir, 0777, true);
$credPath = $credDir.DIRECTORY_SEPARATOR.'.e-uat-local.env';
file_put_contents($credPath, implode(PHP_EOL, [
    '# LOCAL UAT ONLY — do not commit',
    'E_UAT_PASSWORD='.$password,
    'E_CONTRACTOR_CR=CORE-CONTRACTOR-CR-001',
    'E_CONTRACTOR_ADMIN=contractor@core-fixture.test',
    'E_CONTRACTOR_EMPLOYEE=employee@core-fixture.test',
    'E_OWNER_CR=CORE-OWNER-CR-001',
    'E_OWNER_USER=owner@core-fixture.test',
    'E_CONSULTANT_CR=CORE-CONSULTANT-CR-001',
    'E_CONSULTANT_USER=consultant@core-fixture.test',
    'E_PLATFORM_EMAIL=platform-smoke@binaa.test',
    'E_SHARED_DB='.$db,
]).PHP_EOL);

echo 'UAT_FIXTURES_OK'.PHP_EOL;
echo 'CRED_FILE='.$credPath.PHP_EOL;
echo 'PASSWORD_SET=yes (not printed)'.PHP_EOL;
