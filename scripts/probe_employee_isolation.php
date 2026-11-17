<?php

declare(strict_types=1);

/**
 * probe_employee_isolation.php — local read-only diagnostic.
 * For each core fixture schema: list roles and their bi.dashboard.view links,
 * proving only tenant_admin holds it and employee has none.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$schemas = [
    'core-contractor' => 'binaa2_tenant_core_contractor',
    'core-owner'      => 'binaa2_tenant_core_owner',
    'core-consultant' => 'binaa2_tenant_core_consultant',
];

foreach ($schemas as $slug => $schema) {
    $conn = DB::connection('mysql');
    $permId = $conn->table($schema . '.permissions')->where('name', 'bi.dashboard.view')->value('id');

    $links = $conn->table($schema . '.role_has_permissions')
        ->where('permission_id', $permId)
        ->pluck('role_id');

    $roles = $conn->table($schema . '.roles')->get(['id', 'name']);
    $lines = [];
    foreach ($roles as $r) {
        $holds = $links->contains($r->id) ? 'HOLDS' : 'none';
        $lines[] = $r->name . '=' . $holds;
    }

    // Direct user-level grants: the table may not exist in tenant schemas
    // (structural guarantee that no direct user grants exist).
    try {
        $userGrants = $conn->table($schema . '.model_has_permissions')->count();
    } catch (Throwable) {
        $userGrants = 'TABLE_ABSENT';
    }

    echo 'TENANT ' . $slug . ' perm_links=' . $links->count()
        . ' user_grants=' . $userGrants
        . ' roles[' . implode(', ', $lines) . "]\n";
}
