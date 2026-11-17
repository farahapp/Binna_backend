<?php

declare(strict_types=1);

/**
 * Local-only: attach valid CR identifiers + sync SPA passwords for core-* fixtures.
 * Does not print the password. Writes length-only status.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$passwordFile = __DIR__.'/../.fixture_password.local';
$password = getenv('BINAA2_FIXTURE_PASSWORD') ?: '';
if ($password === '' && is_file($passwordFile)) {
    $password = trim((string) file_get_contents($passwordFile));
}
if ($password === '') {
    $password = 'CoreFix#LocalTest99Aa1';
}
file_put_contents($passwordFile, $password);

$fixtures = [
    ['slug' => 'core-owner', 'email' => 'owner@core-fixture.test', 'cr' => 'CORE-OWNER-CR-001'],
    ['slug' => 'core-consultant', 'email' => 'consultant@core-fixture.test', 'cr' => 'CORE-CONSULTANT-CR-001'],
    ['slug' => 'core-contractor', 'email' => 'contractor@core-fixture.test', 'cr' => 'CORE-CONTRACTOR-CR-001'],
];

foreach ($fixtures as $f) {
    $tenant = Tenant::on('mysql')->where('slug', $f['slug'])->first();
    if ($tenant === null) {
        echo 'MISSING_TENANT='.$f['slug'].PHP_EOL;
        continue;
    }

    $tenant->legal_type = 'organization';
    if ($tenant->status !== 'active') {
        $tenant->status = 'active';
    }
    $tenant->save();

    $existing = TenantIdentifier::on('mysql')
        ->where('tenant_id', $tenant->id)
        ->where('type', 'cr')
        ->first();

    if ($existing === null) {
        $id = new TenantIdentifier;
        $id->setConnection('mysql');
        $id->tenant_id = $tenant->id;
        $id->type = 'cr';
        $id->number = $f['cr'];
        $id->validity_status = 'valid';
        $id->expires_at = null;
        $id->save();
        echo 'CR_CREATED='.$f['slug'].' number='.$f['cr'].PHP_EOL;
    } else {
        $existing->number = $f['cr'];
        $existing->validity_status = 'valid';
        $existing->expires_at = null;
        $existing->save();
        echo 'CR_UPDATED='.$f['slug'].' number='.$f['cr'].PHP_EOL;
    }

    if (empty($tenant->schema_name)) {
        echo 'SKIP_USER_NO_SCHEMA='.$f['slug'].PHP_EOL;
        continue;
    }

    $base = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($base, ['database' => $tenant->schema_name])]);
    DB::purge('tenant');
    DB::reconnect('tenant');

    if (! Schema::connection('tenant')->hasTable('users')) {
        echo 'NO_USERS_TABLE='.$f['slug'].PHP_EOL;
        continue;
    }

    $user = User::on('tenant')->where('email', $f['email'])->first();
    if ($user === null) {
        $user = new User;
        $user->setConnection('tenant');
        $user->name = $f['slug'].' Admin';
        $user->email = $f['email'];
        // User model casts password => hashed — assign plaintext once.
        $user->password = $password;
        if (Schema::connection('tenant')->hasColumn('users', 'tenant_slug')) {
            $user->tenant_slug = $f['slug'];
        }
        $user->save();
        echo 'USER_CREATED='.$f['email'].PHP_EOL;
    } else {
        $user->password = $password;
        $user->save();
        echo 'USER_PASSWORD_SYNCED='.$f['email'].PHP_EOL;
    }
}

echo 'SPA_LOGIN_READY=1 password_len='.strlen($password).PHP_EOL;
echo 'CRS='.implode(',', array_column($fixtures, 'cr')).PHP_EOL;
echo 'EMAILS='.implode(',', array_column($fixtures, 'email')).PHP_EOL;
