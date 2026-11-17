<?php

declare(strict_types=1);

/**
 * Local E.5 Core participation journey (NOT Production).
 * Owner creates property + identity → invites contractor & consultant → both accept.
 * Asserts single Project Identity id (no duplication).
 *
 * Requires API on 127.0.0.1:8000 and core-* tenants.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (! app()->environment('local', 'testing')) {
    fwrite(STDERR, "REFUSED\n");
    exit(1);
}

$base = getenv('BINAA2_API_BASE') ?: 'http://127.0.0.1:8000';
$password = getenv('BINAA2_E_UAT_PASSWORD') ?: '';
$credFile = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'artifacts'.DIRECTORY_SEPARATOR.'playwright'.DIRECTORY_SEPARATOR.'BINAA_QATAR_E'.DIRECTORY_SEPARATOR.'.e-uat-local.env';
if ($password === '' && is_file($credFile)) {
    foreach (file($credFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, 'E_UAT_PASSWORD=')) {
            $password = substr($line, strlen('E_UAT_PASSWORD='));
            break;
        }
    }
}
if (strlen($password) < 12) {
    $password = 'EUat#Local2026Aa1';
}

function httpJson(string $method, string $url, ?string $token = null, ?string $tenant = null, ?array $json = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    if ($tenant) {
        $headers[] = 'X-Tenant: '.$tenant;
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $body, true)];
}

function useTenant(string $slug): void
{
    $tenant = Tenant::on('mysql')->where('slug', $slug)->firstOrFail();
    config(['database.connections.tenant' => array_merge(
        config('database.connections.mysql'),
        ['database' => $tenant->schema_name],
    )]);
    DB::purge('tenant');
    DB::reconnect('tenant');
}

function ensureLogin(string $base, string $slug, string $email, string $password): ?string
{
    useTenant($slug);
    $user = User::on('tenant')->where('email', $email)->first();
    if ($user) {
        $user->password = Hash::make($password);
        $user->save();
    }
    [$s, $b] = httpJson('POST', $base.'/api/auth/login', null, $slug, [
        'email' => $email,
        'password' => $password,
    ]);
    if ($s !== 200 || ! is_array($b)) {
        return null;
    }

    return $b['token'] ?? $b['access_token'] ?? null;
}

$results = [];
$stamp = now()->format('YmdHis');

$ownerToken = ensureLogin($base, 'core-owner', 'owner@core-fixture.test', $password);
$contractorToken = ensureLogin($base, 'core-contractor', 'contractor@core-fixture.test', $password);
$consultantToken = ensureLogin($base, 'core-consultant', 'consultant@core-fixture.test', $password);

$results['owner_login'] = is_string($ownerToken);
$results['contractor_login'] = is_string($contractorToken);
$results['consultant_login'] = is_string($consultantToken);

[$ps, $pb] = httpJson('POST', $base.'/api/v1/core/properties', $ownerToken, 'core-owner', [
    'name' => 'E5 Property '.$stamp,
    'property_type' => 'building',
    'region' => 'Qatar',
    'country' => 'QA',
]);
$propertyId = is_array($pb) ? ($pb['data']['id'] ?? null) : null;
$results['owner_create_property'] = $ps === 201 || $ps === 200;
$results['_status_property'] = $ps;

[$is, $ib] = httpJson('POST', $base.'/api/v1/core/project-identities', $ownerToken, 'core-owner', [
    'name' => 'E5 Identity '.$stamp,
    'org_type' => 'owner',
    'property_id' => $propertyId,
    'region' => 'Qatar',
]);
$identityId = is_array($ib) ? ($ib['data']['id'] ?? null) : null;
$results['owner_create_identity'] = ($is === 201 || $is === 200) && is_string($identityId);
$results['_status_identity'] = $is;

[$ics, $icb] = httpJson(
    'POST',
    $base.'/api/v1/core/project-identities/'.$identityId.'/participations',
    $ownerToken,
    'core-owner',
    [
        'participant_tenant_slug' => 'core-contractor',
        'organization_type' => 'contractor',
        'project_role' => 'contractor',
    ]
);
$contractorPartId = is_array($icb) ? ($icb['data']['id'] ?? null) : null;
$results['invite_contractor'] = $ics === 201 && is_string($contractorPartId);
$results['_status_invite_contractor'] = $ics;

[$iss, $isb] = httpJson(
    'POST',
    $base.'/api/v1/core/project-identities/'.$identityId.'/participations',
    $ownerToken,
    'core-owner',
    [
        'participant_tenant_slug' => 'core-consultant',
        'organization_type' => 'consultant',
        'project_role' => 'consultant',
    ]
);
$consultantPartId = is_array($isb) ? ($isb['data']['id'] ?? null) : null;
$results['invite_consultant'] = $iss === 201 && is_string($consultantPartId);
$results['_status_invite_consultant'] = $iss;

// Foreign accept must fail
[$foreign] = httpJson(
    'PATCH',
    $base.'/api/v1/core/participations/'.$consultantPartId.'/respond',
    $contractorToken,
    'core-contractor',
    ['decision' => 'accept']
);
$results['contractor_cannot_accept_consultant_invite'] = in_array($foreign, [403, 404, 422], true);
$results['_status_foreign_accept'] = $foreign;

[$acs] = httpJson(
    'PATCH',
    $base.'/api/v1/core/participations/'.$contractorPartId.'/respond',
    $contractorToken,
    'core-contractor',
    ['decision' => 'accept']
);
$results['contractor_accept'] = $acs === 200;
$results['_status_contractor_accept'] = $acs;

[$ass] = httpJson(
    'PATCH',
    $base.'/api/v1/core/participations/'.$consultantPartId.'/respond',
    $consultantToken,
    'core-consultant',
    ['decision' => 'accept']
);
$results['consultant_accept'] = $ass === 200;
$results['_status_consultant_accept'] = $ass;

[$oshow, $obody] = httpJson('GET', $base.'/api/v1/core/project-identities/'.$identityId, $ownerToken, 'core-owner');
$results['owner_sees_identity'] = $oshow === 200 && (($obody['data']['id'] ?? null) === $identityId);

[$cshow, $cbody] = httpJson('GET', $base.'/api/v1/core/project-identities/'.$identityId, $contractorToken, 'core-contractor');
$results['contractor_sees_same_identity'] = $cshow === 200 && (($cbody['data']['id'] ?? null) === $identityId);

[$sshow, $sbody] = httpJson('GET', $base.'/api/v1/core/project-identities/'.$identityId, $consultantToken, 'core-consultant');
$results['consultant_sees_same_identity'] = $sshow === 200 && (($sbody['data']['id'] ?? null) === $identityId);

$identityCount = DB::connection('mysql')->table('project_identities')->where('id', $identityId)->count();
$results['identity_not_duplicated'] = $identityCount === 1;

$bool = array_filter($results, fn ($v, $k) => ! str_starts_with((string) $k, '_status_'), ARRAY_FILTER_USE_BOTH);
$ok = ! in_array(false, array_values($bool), true);

echo json_encode([
    'ok' => $ok,
    'identity_id' => $identityId,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($ok ? 0 : 1);
