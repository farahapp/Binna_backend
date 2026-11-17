<?php

declare(strict_types=1);

/**
 * E.7 P0 — File metadata + document isolation + profile directory hardening.
 * Local only.
 */

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

$base = getenv('BINAA2_API_BASE') ?: 'http://127.0.0.1:8000';
$password = getenv('BINAA2_E_UAT_PASSWORD') ?: 'EUat#Local2026Aa1';
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

    return [$status, json_decode((string) $body, true)];
}

function httpMultipart(string $url, string $token, string $tenant, string $path): array
{
    $ch = curl_init($url);
    $cfile = new CURLFile($path, 'application/pdf', basename($path));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer '.$token,
            'X-Tenant: '.$tenant,
        ],
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_TIMEOUT => 60,
    ]);
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

    return ($s === 200 && is_array($b)) ? ($b['token'] ?? $b['access_token'] ?? null) : null;
}

$results = [];
$stamp = now()->format('YmdHis');

$ownerToken = ensureLogin($base, 'core-owner', 'owner@core-fixture.test', $password);
$contractorToken = ensureLogin($base, 'core-contractor', 'contractor@core-fixture.test', $password);
$results['logins'] = is_string($ownerToken) && is_string($contractorToken);

// Owner creates document + uploads
[$cs, $cb] = httpJson('POST', $base.'/api/v1/documents', $ownerToken, 'core-owner', [
    'title' => 'E7 Secret Doc '.$stamp,
    'status' => 'draft',
]);
$docId = is_array($cb) ? ($cb['id'] ?? $cb['data']['id'] ?? null) : null;
$results['owner_create_doc'] = in_array($cs, [200, 201], true) && is_string($docId);
$results['owner_create_no_storage_path'] = is_array($cb)
    && ! array_key_exists('storage_path', $cb)
    && ! (isset($cb['data']) && is_array($cb['data']) && array_key_exists('storage_path', $cb['data']));
$results['owner_create_no_tenant_slug_leak'] = is_array($cb)
    && ! array_key_exists('tenant_slug', $cb)
    && ! (isset($cb['data']) && is_array($cb['data']) && array_key_exists('tenant_slug', $cb['data']));

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'e7-'.$stamp.'.pdf';
file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
[$us, $ub] = httpMultipart($base.'/api/v1/documents/'.$docId.'/upload', $ownerToken, 'core-owner', $tmp);
@unlink($tmp);
$results['owner_upload'] = $us === 200;
$results['upload_response_no_storage_path'] = is_array($ub) && ! array_key_exists('storage_path', $ub);

// Contractor list must not include owner doc title
[$ls, $lb] = httpJson('GET', $base.'/api/v1/documents', $contractorToken, 'core-contractor');
$found = false;
$title = 'E7 Secret Doc '.$stamp;
if ($ls === 200 && is_array($lb)) {
    $rows = isset($lb[0]) ? $lb : ($lb['data'] ?? []);
    foreach ($rows as $row) {
        if (is_array($row) && ($row['title'] ?? null) === $title) {
            $found = true;
        }
        if (is_array($row) && array_key_exists('storage_path', $row)) {
            $results['list_leaks_storage_path'] = true;
        }
    }
}
$results['contractor_list_excludes_owner_doc'] = $found === false;
$results['list_no_storage_path'] = empty($results['list_leaks_storage_path']);

[$gs, $gb] = httpJson('GET', $base.'/api/v1/documents/'.$docId, $contractorToken, 'core-contractor');
$results['contractor_get_owner_doc'] = in_array($gs, [403, 404], true);
$results['get_denial_no_title_leak'] = ! (is_array($gb) && (($gb['title'] ?? null) === $title));

[$ds] = httpJson('GET', $base.'/api/v1/documents/'.$docId.'/download', $contractorToken, 'core-contractor');
$results['contractor_download_owner_doc'] = in_array($ds, [403, 404], true);

// Owner can still get own doc
[$ogs, $ogb] = httpJson('GET', $base.'/api/v1/documents/'.$docId, $ownerToken, 'core-owner');
$results['owner_get_own_doc'] = $ogs === 200;
$results['owner_get_has_file'] = is_array($ogb) && (($ogb['has_file'] ?? false) === true);

// Profile directory: foreign draft contact must not leak
[$ps, $pb] = httpJson('POST', $base.'/api/v1/network/profiles', $ownerToken, 'core-owner', [
    'company_name' => 'E7 Private Contact '.$stamp,
    'profile_type' => 'owner',
    'region' => 'Qatar',
    'contact_email' => 'secret-e7@example.test',
    'contact_phone' => '+97400000000',
]);
$profileId = is_array($pb) ? ($pb['data']['id'] ?? null) : null;
$results['profile_create'] = in_array($ps, [200, 201], true) && is_string($profileId);

[$dls, $dlb] = httpJson('GET', $base.'/api/v1/network/profiles?scope=directory', $contractorToken, 'core-contractor');
$dirHasSecretEmail = false;
$dirHasDraftPrivate = false;
if ($dls === 200 && is_array($dlb)) {
    foreach (($dlb['data'] ?? []) as $row) {
        if (! is_array($row)) {
            continue;
        }
        if (array_key_exists('contact_email', $row) || array_key_exists('contact_phone', $row)
            || array_key_exists('managed_by_tenant_slug', $row)) {
            $dirHasSecretEmail = true;
        }
        if (($row['id'] ?? null) === $profileId) {
            $dirHasDraftPrivate = true;
        }
    }
}
$results['directory_no_private_contact_fields'] = $dirHasSecretEmail === false;
$results['directory_excludes_foreign_draft'] = $dirHasDraftPrivate === false;

[$showS, $showB] = httpJson('GET', $base.'/api/v1/network/profiles/'.$profileId, $contractorToken, 'core-contractor');
$results['foreign_draft_show_denied'] = in_array($showS, [403, 404], true);
$results['foreign_draft_no_email_leak'] = ! (is_array($showB)
    && (str_contains(json_encode($showB), 'secret-e7@example.test')));

// Activate then public card only
httpJson('PATCH', $base.'/api/v1/network/profiles/'.$profileId.'/activate', $ownerToken, 'core-owner');
[$show2S, $show2B] = httpJson('GET', $base.'/api/v1/network/profiles/'.$profileId, $contractorToken, 'core-contractor');
$results['foreign_active_show_public'] = $show2S === 200;
$results['foreign_active_no_email'] = is_array($show2B)
    && ! array_key_exists('contact_email', $show2B['data'] ?? [])
    && ! str_contains(json_encode($show2B), 'secret-e7@example.test');

// Link list isolation + engagements list smoke
[$lls, $llb] = httpJson('GET', $base.'/api/v1/core/project-link-requests', $ownerToken, 'core-owner');
$results['link_list_ok'] = $lls === 200 && is_array($llb) && array_key_exists('data', $llb);

[$els] = httpJson('GET', $base.'/api/v1/core/engagements', $ownerToken, 'core-owner');
$results['engagements_list_ok'] = $els === 200;

[$xels] = httpJson('GET', $base.'/api/v1/core/engagements/00000000-0000-0000-0000-000000000001', $contractorToken, 'core-contractor');
$results['engagement_missing_is_404_or_403'] = in_array($xels, [403, 404], true);

$bool = array_filter($results, fn ($v, $k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH);
$ok = ! in_array(false, array_values($bool), true);

echo json_encode(['ok' => $ok, 'doc_id' => $docId, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok ? 0 : 1);
