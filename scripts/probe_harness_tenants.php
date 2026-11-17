<?php

declare(strict_types=1);

/**
 * probe_harness_tenants.php — local read-only diagnostic.
 * Lists tenants in the HARNESS database (binaa2_phpunit) to inspect the stale
 * row reported by the FIX-A-CODE guard in PlatformTenantSeparationTest.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Test-env connection values as used by the local harness.
$db = getenv('DB_DATABASE') ?: config('database.connections.mysql.database');
echo 'RUNTIME_DB=' . $db . "\n";

$rows = DB::connection('mysql')->table('tenants')->orderBy('id')->get(['id', 'slug', 'schema_name', 'status']);
foreach ($rows as $r) {
    echo "HARNESS_TENANT id={$r->id} slug={$r->slug} schema={$r->schema_name} status={$r->status}\n";
}
