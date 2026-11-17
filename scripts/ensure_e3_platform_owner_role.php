<?php

declare(strict_types=1);

/**
 * LOCAL ONLY — ensure platform-owner system role + assign to platform-smoke.
 * Safe additive fix when shared DB has permissions but zero platform_roles.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\PlatformUser;
use Illuminate\Support\Facades\DB;

if (! app()->environment('local', 'testing')) {
    fwrite(STDERR, "REFUSED: only local/testing\n");
    exit(1);
}

$host = (string) config('database.connections.mysql.host');
$db = (string) config('database.connections.mysql.database');
if (! in_array($host, ['127.0.0.1', 'localhost'], true) || $db !== 'binaa2_shared') {
    fwrite(STDERR, "REFUSED: unexpected mysql target\n");
    exit(1);
}

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
    echo "ROLE_CREATED=platform-owner id={$ownerRoleId}\n";
} else {
    echo "ROLE_OK=platform-owner id={$ownerRoleId}\n";
}

$linked = 0;
foreach (DB::connection('mysql')->table('platform_permissions')->pluck('id') as $permissionId) {
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
echo "PERMS_LINKED={$linked}\n";

$plat = PlatformUser::on('mysql')->where('email', 'platform-smoke@binaa.test')->first();
if (! $plat) {
    echo "PLATFORM_USER_MISSING\n";
    exit(1);
}
$hasRole = DB::connection('mysql')->table('platform_user_roles')
    ->where('platform_user_id', $plat->id)
    ->where('platform_role_id', $ownerRoleId)
    ->exists();
if (! $hasRole) {
    DB::connection('mysql')->table('platform_user_roles')->insert([
        'platform_user_id' => $plat->id,
        'platform_role_id' => $ownerRoleId,
    ]);
    echo "USER_ROLE_ASSIGNED\n";
} else {
    echo "USER_ROLE_OK\n";
}

echo 'PERM_CHECK_VIEW='.($plat->fresh()->hasPlatformPermission('platform.tenant_registrations.view') ? '1' : '0').PHP_EOL;
echo 'PERM_CHECK_REVIEW='.($plat->fresh()->hasPlatformPermission('platform.tenant_registrations.review') ? '1' : '0').PHP_EOL;
echo 'DONE'.PHP_EOL;
