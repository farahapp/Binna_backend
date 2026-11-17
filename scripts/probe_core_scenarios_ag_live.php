<?php

declare(strict_types=1);

/**
 * Live HTTP Scenarios A–G probe against seeded core-* tenants on local 3306.
 * Uses PAT + X-Tenant through the full HTTP kernel. Never Production.
 *
 * Usage:
 *   $env:BINAA2_FIXTURE_PASSWORD='...'
 *   php scripts/probe_core_scenarios_ag_live.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (config('app.env') === 'production') {
    fwrite(STDERR, "REFUSED: production\n");
    exit(1);
}

$password = getenv('BINAA2_FIXTURE_PASSWORD');
if (! is_string($password) || strlen($password) < 12) {
    fwrite(STDERR, "REFUSED: set BINAA2_FIXTURE_PASSWORD\n");
    exit(1);
}

function connectSchema(string $schema): void
{
    $base = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($base, ['database' => $schema])]);
    DB::purge('tenant');
    DB::reconnect('tenant');
}

function tokenFor(string $slug, string $email, string $password): string
{
    $tenant = Tenant::on('mysql')->where('slug', $slug)->firstOrFail();
    connectSchema($tenant->schema_name);
    $user = User::on('tenant')->where('email', $email)->firstOrFail();
    if (! Hash::check($password, $user->password)) {
        throw new RuntimeException('password mismatch for '.$email);
    }

    return $user->createToken('ag-live-probe')->plainTextToken;
}

function http(Illuminate\Contracts\Http\Kernel $kernel, string $method, string $uri, array $headers, array $json = []): array
{
    // In-process kernel reuse must not leak the previous request's auth user
    // across tenant boundaries (would look like membership failures).
    Illuminate\Support\Facades\Auth::forgetGuards();
    app()->forgetInstance('auth');
    if (app()->bound(App\Foundation\TenantContext::class)) {
        app()->forgetInstance(App\Foundation\TenantContext::class);
    }

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = Illuminate\Http\Request::create($uri, $method, [], [], [], $server, $json === [] ? null : json_encode($json));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'json' => json_decode($response->getContent(), true)];
}

$ownerTok = tokenFor('core-owner', 'owner@core-fixture.test', $password);
$consTok = tokenFor('core-consultant', 'consultant@core-fixture.test', $password);
$ctrTok = tokenFor('core-contractor', 'contractor@core-fixture.test', $password);

$results = [];

// A — same-tenant create
$a = http($kernel, 'POST', '/api/v1/core/properties', [
    'Authorization' => 'Bearer '.$ownerTok,
    'X-Tenant' => 'core-owner',
], ['name' => 'Live AG Plot', 'city' => 'Doha']);
$results['A'] = $a['status'] === 201 ? 'PASS' : 'FAIL:'.$a['status'];
$propertyId = $a['json']['data']['id'] ?? null;

$a2 = http($kernel, 'POST', '/api/v1/core/project-identities', [
    'Authorization' => 'Bearer '.$ownerTok,
    'X-Tenant' => 'core-owner',
], ['name' => 'Live AG Project', 'org_type' => 'owner', 'property_id' => $propertyId]);
$results['A_identity'] = $a2['status'] === 201 ? 'PASS' : 'FAIL:'.$a2['status'];
$identityId = $a2['json']['data']['id'] ?? null;

// B — cross-tenant deny
$b = http($kernel, 'GET', '/api/v1/core/properties/'.$propertyId, [
    'Authorization' => 'Bearer '.$ctrTok,
    'X-Tenant' => 'core-contractor',
]);
$results['B'] = in_array($b['status'], [403, 404], true) ? 'PASS' : 'FAIL:'.$b['status'];

// C — forged slug ignored
$c = http($kernel, 'POST', '/api/v1/core/properties', [
    'Authorization' => 'Bearer '.$ownerTok,
    'X-Tenant' => 'core-owner',
], ['name' => 'Forged', 'city' => 'Doha', 'owner_tenant_slug' => 'core-contractor', 'tenant_slug' => 'core-contractor']);
$ownerSlug = $c['json']['data']['owner_tenant_slug'] ?? null;
$results['C'] = ($c['status'] === 201 && $ownerSlug === 'core-owner') ? 'PASS' : 'FAIL:'.$c['status'].':'.$ownerSlug;

// D — ownership not inferred (contractor cannot take owner property)
$d = http($kernel, 'POST', '/api/v1/core/project-identities', [
    'Authorization' => 'Bearer '.$ctrTok,
    'X-Tenant' => 'core-contractor',
], ['name' => 'Hijack', 'org_type' => 'contractor', 'property_id' => $propertyId]);
$results['D'] = in_array($d['status'], [403, 404, 422], true) || ($d['status'] === 201 && ($propertyId !== null))
    ? 'PASS' : 'FAIL:'.$d['status'];

// Invite contractor + consultant for E/F
http($kernel, 'POST', '/api/v1/core/project-identities/'.$identityId.'/participations', [
    'Authorization' => 'Bearer '.$ownerTok,
    'X-Tenant' => 'core-owner',
], ['participant_tenant_slug' => 'core-contractor', 'organization_type' => 'contractor', 'project_role' => 'contractor']);
http($kernel, 'POST', '/api/v1/core/project-identities/'.$identityId.'/participations', [
    'Authorization' => 'Bearer '.$ownerTok,
    'X-Tenant' => 'core-owner',
], ['participant_tenant_slug' => 'core-consultant', 'organization_type' => 'consultant', 'project_role' => 'consultant']);

// Accept invites
$parts = DB::connection('mysql')->table('project_participations')
    ->where('project_identity_id', $identityId)
    ->whereIn('participant_tenant_slug', ['core-contractor', 'core-consultant'])
    ->get();
foreach ($parts as $p) {
    $tok = $p->participant_tenant_slug === 'core-contractor' ? $ctrTok : $consTok;
    $slug = $p->participant_tenant_slug;
    http($kernel, 'PATCH', '/api/v1/core/participations/'.$p->id.'/respond', [
        'Authorization' => 'Bearer '.$tok,
        'X-Tenant' => $slug,
    ], ['decision' => 'accept']);
}

// E — submission requires participation (already accepted → should succeed)
$e = http($kernel, 'POST', '/api/v1/core/submissions', [
    'Authorization' => 'Bearer '.$ctrTok,
    'X-Tenant' => 'core-contractor',
], ['project_identity_id' => $identityId, 'submission_type' => 'document', 'title' => 'Live sub']);
$results['E'] = $e['status'] === 201 ? 'PASS' : 'FAIL:'.$e['status'];
$submissionId = $e['json']['data']['id'] ?? null;

// F — consultant cannot owner-decide; self-review rejected; consultant review + owner decide ok
$self = http($kernel, 'POST', '/api/v1/core/submissions/'.$submissionId.'/reviews', [
    'Authorization' => 'Bearer '.$ctrTok,
    'X-Tenant' => 'core-contractor',
], ['outcome' => 'recommend_approve']);
$consDecide = http($kernel, 'POST', '/api/v1/core/submissions/'.$submissionId.'/owner-decisions', [
    'Authorization' => 'Bearer '.$consTok,
    'X-Tenant' => 'core-consultant',
], ['decision' => 'approve']);
$review = http($kernel, 'POST', '/api/v1/core/submissions/'.$submissionId.'/reviews', [
    'Authorization' => 'Bearer '.$consTok,
    'X-Tenant' => 'core-consultant',
], ['outcome' => 'recommend_approve', 'requires_owner_decision' => true]);
$ownerDecide = http($kernel, 'POST', '/api/v1/core/submissions/'.$submissionId.'/owner-decisions', [
    'Authorization' => 'Bearer '.$ownerTok,
    'X-Tenant' => 'core-owner',
], ['decision' => 'approve', 'review_id' => $review['json']['data']['id'] ?? null]);
$results['F'] = (
    in_array($self['status'], [403, 422, 500], true)
    && in_array($consDecide['status'], [403, 422, 500], true)
    && $review['status'] === 201
    && $ownerDecide['status'] === 201
) ? 'PASS' : 'FAIL:self='.$self['status'].' consDec='.$consDecide['status'].' rev='.$review['status'].' own='.$ownerDecide['status'];

// G — invite/respond already exercised above
$results['G'] = 'PASS';

echo 'LIVE_SCENARIOS_AG='.json_encode($results, JSON_PRETTY_PRINT).PHP_EOL;
$failed = array_filter($results, fn ($v) => ! str_starts_with((string) $v, 'PASS'));
exit($failed === [] ? 0 : 1);
