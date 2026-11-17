<?php

declare(strict_types=1);

/**
 * backfill_tenant_permission_catalog_local.php — LOCAL ONLY remediation for
 * EXISTING tenants missing the DEC-BI-IAM-001 catalog permission.
 *
 * Uses IamMigrator::seedPermissionCatalogOnConnection() — the architectural
 * injection point — with a caller-owned connection built from the local
 * runtime mysql credentials. Never touches Production, never prints secrets,
 * and is idempotent (re-running changes nothing once the catalog is in place).
 *
 * Usage: php scripts/backfill_tenant_permission_catalog_local.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\IamMigrator;
use Illuminate\Support\Facades\DB;

if (($app['config']->get('app.env')) === 'production') {
    fwrite(STDERR, "REFUSED: production\n");
    exit(1);
}

$host = (string) config('database.connections.mysql.host');
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "REFUSED: non-local mysql host {$host}\n");
    exit(2);
}

/** @var IamMigrator $migrator */
$migrator = $app->make(IamMigrator::class);

$tenants = DB::connection('mysql')->table('tenants')->orderBy('id')->get(['id', 'slug', 'schema_name']);

foreach ($tenants as $t) {
    if (empty($t->schema_name)) {
        echo "TENANT slug={$t->slug} SKIP no_schema\n";
        continue;
    }

    // Caller-owned connection on the runtime local mysql credentials.
    $connName = 'backfill_' . $t->slug;
    config(['database.connections.' . $connName => array_merge(
        config('database.connections.mysql'),
        ['database' => $t->schema_name]
    )]);
    DB::purge($connName);

    try {
        $conn = DB::connection($connName);
        if (! $conn->getSchemaBuilder()->hasTable('permissions')) {
            echo "TENANT slug={$t->slug} SKIP no_permissions_table (schema={$t->schema_name})\n";
            continue;
        }

        $beforePerm = $conn->table('permissions')->where('name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)->count();

        $migrator->seedPermissionCatalogOnConnection($connName);

        $afterPerm = $conn->table('permissions')->where('name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)->count();
        $linkCount = $conn->table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', IamMigrator::ROLE_TENANT_ADMIN)
            ->where('permissions.name', IamMigrator::PERMISSION_BI_DASHBOARD_VIEW)
            ->count();

        echo 'TENANT slug=' . $t->slug . ' schema=' . $t->schema_name
            . ' backfilled=' . ($beforePerm == 0 ? 'yes' : 'already')
            . " perm_rows={$afterPerm} admin_links={$linkCount}\n";
    } catch (Throwable $e) {
        echo 'TENANT slug=' . $t->slug . ' ERROR ' . substr($e->getMessage(), 0, 120) . "\n";
    } finally {
        DB::purge($connName);
        $connections = config('database.connections', []);
        unset($connections[$connName]);
        config(['database.connections' => $connections]);
    }
}

echo "DONE\n";
