<?php

declare(strict_types=1);

/**
 * fix_bi_permission_fixtures_local.php — LOCAL ONLY remediation for the
 * BI/IAM gap (GET /api/v1/bi/dashboard → 403 for all tenant users).
 *
 * Root cause: IamMigrator seeds the tenant_admin role with zero permissions
 * and no tenant permission catalog is seeded anywhere, while
 * App\BI\ReportingService requires 'bi.dashboard.view'.
 *
 * Minimal, reversible fix scoped to the three LOCAL fixture tenants only:
 *   1. Create the single permission row 'bi.dashboard.view' in each fixture
 *      tenant schema (no other permission is added).
 *   2. Assign it to the existing 'tenant_admin' role through the sanctioned
 *      IAM API (POST /api/iam/roles/{role}/permissions/{permission}) — this
 *      exercises the designed company self-governance journey (E4).
 *   3. Verify: role permissions == exactly ['bi.dashboard.view'], and
 *      GET /api/v1/bi/dashboard returns 200.
 *
 * Guards: refuses production env / non-local API host. Prints no secrets.
 * Usage: php scripts/fix_bi_permission_fixtures_local.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

if (($app['config']->get('app.env')) === 'production') {
    fwrite(STDERR, "REFUSED: production\n");
    exit(1);
}

$passwordFile = __DIR__ . '/../.fixture_password.local';
$password = getenv('BINAA2_FIXTURE_PASSWORD') ?: '';
if ($password === '' && is_file($passwordFile)) {
    $password = trim((string) file_get_contents($passwordFile));
}
if ($password === '') {
    fwrite(STDERR, "NO_FIXTURE_PASSWORD\n");
    exit(2);
}

$base = (string) config('app.url');
$api = ($base !== '' && str_contains($base, '127.0.0.1')) ? $base : 'http://127.0.0.1:8000';
echo "API={$api}\n";

$fixtures = [
    ['slug' => 'core-contractor', 'email' => 'contractor@core-fixture.test'],
    ['slug' => 'core-owner',      'email' => 'owner@core-fixture.test'],
    ['slug' => 'core-consultant', 'email' => 'consultant@core-fixture.test'],
];

const BI_PERMISSION = 'bi.dashboard.view';

foreach ($fixtures as $f) {
    echo '--- ' . $f['slug'] . "\n";

    $tenant = DB::connection('mysql')->table('tenants')->where('slug', $f['slug'])->first();
    if (! $tenant || empty($tenant->schema_name)) {
        echo "MISSING_TENANT={$f['slug']}\n";
        continue;
    }

    $baseConn = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($baseConn, ['database' => $tenant->schema_name])]);
    DB::purge('tenant');
    $tenantDb = DB::connection('tenant');

    // 1) Create exactly ONE permission row (idempotent).
    $permId = $tenantDb->table('permissions')->where('name', BI_PERMISSION)->value('id');
    if (! $permId) {
        $permId = $tenantDb->table('permissions')->insertGetId([
            'name' => BI_PERMISSION,
            'guard_name' => 'api',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "PERMISSION_CREATED id={$permId}\n";
    } else {
        echo "PERMISSION_EXISTS id={$permId}\n";
    }

    // 2) Assign via the sanctioned IAM API as the tenant admin.
    $login = Http::acceptJson()->withHeaders(['X-Tenant' => $f['slug']])->post($api . '/api/auth/login', [
        'email' => $f['email'],
        'password' => $password,
    ]);
    if (! $login->successful()) {
        echo 'LOGIN_FAIL status=' . $login->status() . "\n";
        continue;
    }
    $token = $login->json('token') ?? '';
    echo 'LOGIN_OK token_len=' . strlen($token) . "\n";

    $headers = ['Authorization' => 'Bearer ' . $token, 'X-Tenant' => $f['slug']];
    $roles = Http::acceptJson()->withHeaders($headers)->get($api . '/api/iam/roles');
    $rolesData = $roles->json('data') ?? [];
    $adminRole = null;
    foreach ($rolesData as $r) {
        echo 'ROLE_BEFORE name=' . $r['name'] . ' perms=' . implode(',', $r['permissions']) . "\n";
        if ($r['name'] === 'tenant_admin') {
            $adminRole = $r;
        }
    }
    if (! $adminRole) {
        echo "NO_TENANT_ADMIN_ROLE\n";
        continue;
    }

    $assign = Http::acceptJson()->withHeaders($headers)
        ->post($api . '/api/iam/roles/' . $adminRole['id'] . '/permissions/' . $permId);
    echo 'ASSIGN status=' . $assign->status() . ' body=' . $assign->body() . "\n";

    // 3) Verify.
    $rolesAfter = Http::acceptJson()->withHeaders($headers)->get($api . '/api/iam/roles');
    foreach (($rolesAfter->json('data') ?? []) as $r) {
        echo 'ROLE_AFTER name=' . $r['name'] . ' perms=' . implode(',', $r['permissions']) . "\n";
    }

    $bi = Http::acceptJson()->withHeaders($headers)->get($api . '/api/v1/bi/dashboard');
    echo 'BI_DASHBOARD status=' . $bi->status() . ' body_len=' . strlen($bi->body()) . "\n";
}

echo "DONE\n";
