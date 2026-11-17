<?php

declare(strict_types=1);

/**
 * BINAA QATAR D.5 — local-only authenticated UI fixtures.
 *
 * Ensures core-* tenants have valid CR identifiers + users with a known local
 * password, and a dedicated platform-smoke operator password reset.
 *
 * NEVER run against Production. Does not print the password.
 *
 * Usage (from backend/, PHP 8.4+):
 *   set BINAA2_D5_PASSWORD=D5Local#Test2026Aa1
 *   php scripts/seed_d5_visual_fixtures.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
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
if (! in_array($db, ['binaa2_shared', 'binaa2_phpunit', 'binaa2_testing'], true)) {
    fwrite(STDERR, "REFUSED: unexpected shared database {$db}\n");
    exit(1);
}

$password = getenv('BINAA2_D5_PASSWORD');
if (! is_string($password) || strlen($password) < 12) {
    $password = 'D5Local#Test2026Aa1';
}

$fixtures = [
    [
        'slug' => 'core-owner',
        'email' => 'owner@core-fixture.test',
        'cr' => 'CORE-OWNER-CR-001',
        'activity_type' => 'owner',
        'name' => 'Core Owner Org',
    ],
    [
        'slug' => 'core-consultant',
        'email' => 'consultant@core-fixture.test',
        'cr' => 'CORE-CONSULTANT-CR-001',
        'activity_type' => 'consultant',
        'name' => 'Core Consultant Org',
    ],
    [
        'slug' => 'core-contractor',
        'email' => 'contractor@core-fixture.test',
        'cr' => 'CORE-CONTRACTOR-CR-001',
        'activity_type' => 'contractor',
        'name' => 'Core Contractor Org',
    ],
];

function connectTenantSchema(string $schema): void
{
    $base = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($base, ['database' => $schema])]);
    DB::purge('tenant');
    DB::reconnect('tenant');
}

foreach ($fixtures as $f) {
    $tenant = Tenant::on('mysql')->where('slug', $f['slug'])->first();
    if ($tenant === null) {
        echo 'MISSING_TENANT='.$f['slug'].' — run seed_core_multitenant_fixtures.php first'.PHP_EOL;
        continue;
    }

    $tenant->legal_type = 'organization';
    $tenant->activity_type = $f['activity_type'];
    $tenant->status = 'active';
    $tenant->save();

    $hasCr = TenantIdentifier::on('mysql')
        ->where('tenant_id', $tenant->id)
        ->where('type', 'cr')
        ->where('validity_status', 'valid')
        ->exists();

    if (! $hasCr) {
        TenantIdentifier::on('mysql')->create([
            'tenant_id' => $tenant->id,
            'type' => 'cr',
            'number' => $f['cr'],
            'validity_status' => 'valid',
            'expires_at' => null,
        ]);
        echo 'CR_CREATED='.$f['slug'].PHP_EOL;
    } else {
        echo 'CR_OK='.$f['slug'].PHP_EOL;
    }

    if (empty($tenant->schema_name)) {
        echo 'SKIP_USER_NO_SCHEMA='.$f['slug'].PHP_EOL;
        continue;
    }

    connectTenantSchema($tenant->schema_name);

    if (! Schema::connection('tenant')->hasTable('users')) {
        echo 'IAM_MISSING='.$f['slug'].PHP_EOL;
        continue;
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
        if ($role !== null && ! $user->hasRole('tenant_admin')) {
            $user->assignRole($role);
            echo 'ROLE_ASSIGNED='.$f['slug'].PHP_EOL;
        }
    }
}

$platformEmail = 'platform-smoke@binaa.test';
$plat = PlatformUser::on('mysql')->where('email', $platformEmail)->first();
if ($plat === null) {
    $plat = new PlatformUser;
    $plat->setConnection('mysql');
    $plat->name = 'D5 Platform Smoke';
    $plat->email = $platformEmail;
    $plat->password = Hash::make($password);
    $plat->is_active = true;
    $plat->save();
    echo 'PLATFORM_CREATED='.$platformEmail.PHP_EOL;
} else {
    $plat->password = Hash::make($password);
    $plat->is_active = true;
    $plat->save();
    echo 'PLATFORM_UPDATED='.$platformEmail.PHP_EOL;
}

$credPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'artifacts'.DIRECTORY_SEPARATOR.'playwright'.DIRECTORY_SEPARATOR.'BINAA_QATAR_D5'.DIRECTORY_SEPARATOR.'.d5-local.env';
@mkdir(dirname($credPath), 0777, true);
$lines = [
    '# LOCAL ONLY — do not commit. Generated by seed_d5_visual_fixtures.php',
    'D5_PASSWORD='.$password,
    'D5_CONTRACTOR_CR=CORE-CONTRACTOR-CR-001',
    'D5_CONTRACTOR_EMAIL=contractor@core-fixture.test',
    'D5_OWNER_CR=CORE-OWNER-CR-001',
    'D5_OWNER_EMAIL=owner@core-fixture.test',
    'D5_CONSULTANT_CR=CORE-CONSULTANT-CR-001',
    'D5_CONSULTANT_EMAIL=consultant@core-fixture.test',
    'D5_PLATFORM_EMAIL='.$platformEmail,
    'D5_SHARED_DB='.$db,
    'D5_MYSQL_HOST='.$host,
    'D5_MYSQL_PORT='.$port,
];
file_put_contents($credPath, implode(PHP_EOL, $lines).PHP_EOL);

echo 'FIXTURES_OK=d5'.PHP_EOL;
echo 'CRED_FILE='.$credPath.PHP_EOL;
echo 'PASSWORD_SET=yes (not printed)'.PHP_EOL;
