<?php

declare(strict_types=1);

/**
 * probe_catalog_width.php — local read-only diagnostic.
 * Lists ALL permission names per local tenant schema to prove the catalog
 * was not expanded beyond DEC-BI-IAM-001.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tenants = DB::connection('mysql')->table('tenants')->orderBy('id')->get(['slug', 'schema_name']);

foreach ($tenants as $t) {
    $names = DB::connection('mysql')->table($t->schema_name . '.permissions')->pluck('name')->implode(', ');
    echo 'TENANT ' . $t->slug . ' permissions=[' . $names . "]\n";
}
