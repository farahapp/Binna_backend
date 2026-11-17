<?php

declare(strict_types=1);

/**
 * probe_bi_permission_fixtures.php — local read-only diagnostic.
 * Checks whether the core-* fixture tenants' tenant_admin roles carry the
 * bi.dashboard.view permission required by ReportingService.
 * Reads only; prints no secrets. Local MySQL only.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['core-contractor', 'core-owner', 'core-consultant'] as $slug) {
    $t = DB::connection('mysql')->table('tenants')->where('slug', $slug)->first();
    if (! $t) {
        echo "MISSING_TENANT={$slug}\n";
        continue;
    }
    echo "TENANT {$slug} schema={$t->schema_name} status={$t->status}\n";

    $base = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($base, ['database' => $t->schema_name])]);
    DB::purge('tenant');

    if (! DB::connection('tenant')->getSchemaBuilder()->hasTable('permissions')) {
        echo "NO_PERMISSIONS_TABLE={$slug}\n";
        continue;
    }
    $perms = DB::connection('tenant')->table('permissions')->pluck('name')->all();
    echo 'PERMS_COUNT=' . count($perms) . ' bi_dashboard_view=' . (in_array('bi.dashboard.view', $perms, true) ? 'YES' : 'NO') . "\n";

    $emailMap = [
        'core-contractor' => 'contractor@core-fixture.test',
        'core-owner'      => 'owner@core-fixture.test',
        'core-consultant' => 'consultant@core-fixture.test',
    ];
    $u = DB::connection('tenant')->table('users')->where('email', $emailMap[$slug])->first();
    if (! $u) {
        echo "NO_USER={$slug}\n";
        continue;
    }
    $roleLinks = DB::connection('tenant')->table('model_has_roles')->where('model_id', $u->id)->get();
    foreach ($roleLinks as $link) {
        $role = DB::connection('tenant')->table('roles')->where('id', $link->role_id)->first();
        echo 'USER_ROLE=' . ($role->name ?? ('id:' . $link->role_id)) . "\n";
        if ($role) {
            $rp = DB::connection('tenant')->table('role_has_permissions')->where('role_id', $role->id)->count();
            echo 'ROLE_PERMS_COUNT=' . $rp . "\n";
        }
    }
}
