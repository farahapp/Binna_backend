<?php

declare(strict_types=1);

/**
 * probe_tenant_permission_catalog.php — local read-only diagnostic.
 * Lists every tenant in the LOCAL shared DB with its schema and whether the
 * bi.dashboard.view permission (and its tenant_admin link) exists.
 * Prints no secrets. Refuses non-local.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const BI_PERMISSION = 'bi.dashboard.view';

$host = (string) config('database.connections.mysql.host');
$port = (string) config('database.connections.mysql.port');
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "REFUSED: non-local host {$host}\n");
    exit(1);
}

$tenants = DB::connection('mysql')->table('tenants')->orderBy('id')->get(['id', 'slug', 'schema_name', 'status']);
echo 'TENANT_COUNT=' . $tenants->count() . "\n";

foreach ($tenants as $t) {
    $state = 'NO_SCHEMA_ROW';
    if (! empty($t->schema_name)) {
        try {
            $hasPermTable = DB::connection('mysql')->selectOne(
                'SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$t->schema_name, 'permissions']
            );
            if ((int) $hasPermTable->c === 0) {
                $state = 'NO_PERMISSIONS_TABLE';
            } else {
                $perm = DB::connection('mysql')->selectOne(
                    "SELECT id FROM `{$t->schema_name}`.`permissions` WHERE name = ?",
                    [BI_PERMISSION]
                );
                if (! $perm) {
                    $state = 'PERMISSION_MISSING';
                } else {
                    $link = DB::connection('mysql')->selectOne(
                        "SELECT COUNT(*) c FROM `{$t->schema_name}`.`role_has_permissions` rhp
                         JOIN `{$t->schema_name}`.`roles` r ON r.id = rhp.role_id AND r.name = 'tenant_admin'
                         WHERE rhp.permission_id = ?",
                        [$perm->id]
                    );
                    $state = ((int) $link->c) > 0 ? 'OK' : 'LINK_MISSING';
                }
            }
        } catch (Throwable $e) {
            $state = 'ERR:' . substr($e->getMessage(), 0, 60);
        }
    }
    echo "TENANT slug={$t->slug} schema={$t->schema_name} status={$t->status} catalog={$state}\n";
}
