<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (! app()->environment('local', 'testing')) {
    fwrite(STDERR, "REFUSED\n");
    exit(1);
}

$base = 'http://127.0.0.1:8000';
$password = 'EUat#Local2026Aa1';
$credFile = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'artifacts'.DIRECTORY_SEPARATOR.'playwright'.DIRECTORY_SEPARATOR.'BINAA_QATAR_E'.DIRECTORY_SEPARATOR.'.e-uat-local.env';
if (is_file($credFile)) {
    foreach (file($credFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, 'E_UAT_PASSWORD=')) {
            $password = substr($line, strlen('E_UAT_PASSWORD='));
            break;
        }
    }
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
        CURLOPT_TIMEOUT => 45,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $body];
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
    $j = json_decode((string) $b, true);

    return ($s === 200 && is_array($j)) ? ($j['token'] ?? $j['access_token'] ?? null) : null;
}

$stamp = date('His');
$ot = ensureLogin($base, 'core-owner', 'owner@core-fixture.test', $password);
$ct = ensureLogin($base, 'core-consultant', 'consultant@core-fixture.test', $password);

[$s, $b] = httpJson('POST', $base.'/api/v1/network/profiles', $ct, 'core-consultant', [
    'company_name' => 'DbgRival'.$stamp,
    'profile_type' => 'contractor',
    'region' => 'Qatar',
]);
echo "CREATE_CONTRACTOR_PROFILE_ON_CONSULTANT=$s $b\n";
$pid = json_decode((string) $b, true)['data']['id'] ?? null;

[$s, $b] = httpJson('GET', $base.'/api/v1/network/profiles?type=owner', $ot, 'core-owner');
$op = json_decode((string) $b, true)['data'][0]['id'] ?? null;
echo "OWNER_PROFILE=$op\n";

[$s, $b] = httpJson('POST', $base.'/api/v1/network/tenders', $ot, 'core-owner', [
    'publisher_profile_id' => $op,
    'title' => 'Dbg'.$stamp,
    'category' => 'construction',
    'visibility' => 'public',
    'region' => 'Qatar',
    'deadline' => date('c', time() + 86400),
    'participant_types' => ['contractor'],
]);
echo "TENDER=$s $b\n";
$tid = json_decode((string) $b, true)['data']['id'] ?? null;
httpJson('PATCH', $base.'/api/v1/network/tenders/'.$tid.'/publish', $ot, 'core-owner');

[$s, $b] = httpJson('POST', $base.'/api/v1/network/bids', $ct, 'core-consultant', [
    'tender_id' => $tid,
    'contractor_profile_id' => $pid,
    'content' => ['amount' => 1],
]);
echo "RIVAL_BID=$s $b\n";

[$s1, $b1] = httpJson('POST', $base.'/api/v1/core/project-identities', $ot, 'core-owner', [
    'name' => 'DbgSrc'.$stamp,
    'org_type' => 'owner',
    'region' => 'Qatar',
]);
[$s2, $b2] = httpJson('POST', $base.'/api/v1/core/project-identities', $ot, 'core-owner', [
    'name' => 'DbgTgt'.$stamp,
    'org_type' => 'owner',
    'region' => 'Qatar',
]);
$src = json_decode((string) $b1, true)['data']['id'] ?? null;
$tgt = json_decode((string) $b2, true)['data']['id'] ?? null;
echo "IDENTITIES src=$src tgt=$tgt\n";

[$s, $b] = httpJson('POST', $base.'/api/v1/core/project-link-requests', $ot, 'core-owner', [
    'source_project_identity_id' => $src,
    'target_project_identity_id' => $tgt,
    'requester_org_type' => 'owner',
    'match_type' => 'manual',
]);
echo "LINK_CREATE=$s $b\n";
$lid = json_decode((string) $b, true)['data']['id'] ?? null;

[$s, $b] = httpJson('PATCH', $base.'/api/v1/core/project-link-requests/'.$lid.'/decide', $ot, 'core-owner', [
    'decision' => 'approve',
    'reason' => 'debug',
]);
echo "LINK_DECIDE=$s $b\n";

$row = DB::connection('mysql')->table('project_identities')->whereIn('id', [$src, $tgt])->get(['id', 'status', 'verified_owner_tenant_slug']);
echo 'IDENTITIES_DB='.json_encode($row, JSON_PRETTY_PRINT)."\n";
