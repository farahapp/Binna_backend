<?php

declare(strict_types=1);

/**
 * probe_contractor_projects.php — local read-only diagnostic.
 * Lists projects rows in the contractor tenant schema (and count).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$schema = 'binaa2_tenant_core_contractor';
$conn = DB::connection('mysql');

$hasTable = $conn->selectOne(
    'SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [$schema, 'projects']
);
echo 'PROJECTS_TABLE_EXISTS=' . ((int) $hasTable->c) . "\n";

if ((int) $hasTable->c > 0) {
    $rows = $conn->table($schema . '.projects')->get();
    echo 'PROJECTS_COUNT=' . $rows->count() . "\n";
    foreach ($rows as $r) {
        echo 'PROJECT id=' . $r->id . ' name=' . ($r->name ?? 'null') . ' status=' . ($r->status ?? 'null')
            . ' code=' . ($r->code ?? ($r->project_code ?? 'null')) . "\n";
    }
}
