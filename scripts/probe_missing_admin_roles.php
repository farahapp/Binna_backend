<?php

declare(strict_types=1);

/**
 * probe_missing_admin_roles.php — local read-only diagnostic.
 * Lists roles in the two schemas that got the catalog permission but no
 * tenant_admin link, to determine whether tenant_admin exists there at all.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach (['auth-smoke-b', 's29-e2e-test'] as $slug) {
    $t = DB::connection('mysql')->table('tenants')->where('slug', $slug)->first();
    $roles = DB::connection('mysql')->table($t->schema_name . '.roles')->get(['id', 'name']);
    echo $slug . ' (' . $t->schema_name . ') roles: ' . $roles->map(fn ($r) => $r->id . ':' . $r->name)->implode(', ') . "\n";
}
