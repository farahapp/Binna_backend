<?php

declare(strict_types=1);

/**
 * Local E.4 staff isolation probes via live HTTP (NOT Production).
 * Requires API on 127.0.0.1:8000.
 *
 * Usage: php scripts/e2e_e4_staff_isolation.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\PlatformUser;
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
$results = [];

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
        CURLOPT_TIMEOUT => 20,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return [0, ['error' => $err]];
    }

    return [$status, json_decode($body, true)];
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

// Ensure limited platform staff
$limitedRoleId = DB::connection('mysql')->table('platform_roles')->where('slug', 'platform-ops-viewer')->value('id');
if ($limitedRoleId === null) {
    $limitedRoleId = DB::connection('mysql')->table('platform_roles')->insertGetId([
        'name' => 'Platform Ops Viewer',
        'slug' => 'platform-ops-viewer',
        'description' => 'E4 limited staff — view tenants only',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
$viewTenant = DB::connection('mysql')->table('platform_permissions')->where('name', 'platform.tenants.view')->value('id');
if ($viewTenant) {
    DB::connection('mysql')->table('platform_role_permissions')->insertOrIgnore([
        'platform_role_id' => $limitedRoleId,
        'platform_permission_id' => $viewTenant,
    ]);
}

$limited = PlatformUser::on('mysql')->firstOrCreate(
    ['email' => 'e4-platform-limited@binaa.test'],
    ['name' => 'E4 Limited Staff', 'password' => Hash::make($password), 'is_active' => true],
);
$limited->password = Hash::make($password);
$limited->is_active = true;
$limited->save();
DB::connection('mysql')->table('platform_user_roles')->where('platform_user_id', $limited->id)->delete();
DB::connection('mysql')->table('platform_user_roles')->insert([
    'platform_user_id' => $limited->id,
    'platform_role_id' => $limitedRoleId,
]);

$owner = PlatformUser::on('mysql')->where('email', 'platform-smoke@binaa.test')->firstOrFail();
$owner->password = Hash::make($password);
$owner->is_active = true;
$owner->save();

[$os, $ob] = httpJson('POST', $base.'/api/platform/auth/login', null, null, [
    'email' => 'platform-smoke@binaa.test',
    'password' => $password,
]);
$ownerToken = is_array($ob) ? ($ob['token'] ?? null) : null;
$results['platform_owner_login'] = $os === 200 && is_string($ownerToken);

[$ls, $lb] = httpJson('POST', $base.'/api/platform/auth/login', null, null, [
    'email' => 'e4-platform-limited@binaa.test',
    'password' => $password,
]);
$limitedToken = is_array($lb) ? ($lb['token'] ?? null) : null;
$results['platform_limited_login'] = $ls === 200 && is_string($limitedToken);

[$s, $body] = httpJson('GET', $base.'/api/platform/users?per_page=5', $ownerToken);
$results['platform_owner_lists_users'] = $s === 200;
$results['platform_users_summary'] = is_array($body) && isset($body['summary']['total']);
$results['_status_owner_users'] = $s;

[$s] = httpJson('GET', $base.'/api/platform/users', $limitedToken);
$results['platform_limited_cannot_list_users'] = $s === 403;
$results['_status_limited_users'] = $s;

[$s] = httpJson('POST', $base.'/api/platform/users', $limitedToken, null, [
    'name' => 'Nope',
    'email' => 'nope-'.Str::lower(Str::random(6)).'@binaa.test',
    'password' => $password,
    'password_confirmation' => $password,
    'is_active' => false,
    'role_ids' => [],
]);
$results['platform_limited_cannot_create_users'] = $s === 403;
$results['_status_limited_create'] = $s;

// Ensure tenant admin passwords match UAT password used for login
foreach (['core-contractor' => 'contractor@core-fixture.test', 'core-consultant' => 'consultant@core-fixture.test'] as $slug => $email) {
    useTenant($slug);
    $u = User::on('tenant')->where('email', $email)->first();
    if ($u) {
        $u->password = Hash::make($password);
        $u->save();
    }
}

[$cs, $cb] = httpJson('POST', $base.'/api/auth/login', null, 'core-contractor', [
    'email' => 'contractor@core-fixture.test',
    'password' => $password,
]);
$cToken = is_array($cb) ? ($cb['token'] ?? $cb['access_token'] ?? null) : null;
$results['contractor_login'] = $cs === 200 && is_string($cToken);

[$ssLogin, $sb] = httpJson('POST', $base.'/api/auth/login', null, 'core-consultant', [
    'email' => 'consultant@core-fixture.test',
    'password' => $password,
]);
$sToken = is_array($sb) ? ($sb['token'] ?? $sb['access_token'] ?? null) : null;
$results['consultant_login'] = $ssLogin === 200 && is_string($sToken);

[$cs2, $cUsers] = httpJson('GET', $base.'/api/iam/users', $cToken, 'core-contractor');
$results['contractor_iam_list'] = $cs2 === 200;
$results['_status_contractor_iam'] = $cs2;
$contractorEmails = collect($cUsers['data'] ?? [])->pluck('email')->all();

[$ss2, $sUsers] = httpJson('GET', $base.'/api/iam/users', $sToken, 'core-consultant');
$results['consultant_iam_list'] = $ss2 === 200;
$results['_status_consultant_iam'] = $ss2;
$consultantEmails = collect($sUsers['data'] ?? [])->pluck('email')->all();

$results['contractor_cannot_see_consultant_emails'] = ! in_array('consultant@core-fixture.test', $contractorEmails, true);
$results['consultant_cannot_see_contractor_emails'] = ! in_array('contractor@core-fixture.test', $consultantEmails, true);

[$cross] = httpJson('GET', $base.'/api/iam/users', $cToken, 'core-consultant');
$results['contractor_token_on_consultant_slug_rejected'] = in_array($cross, [401, 403, 404], true);
$results['_status_cross_tenant'] = $cross;

[$ps] = httpJson('GET', $base.'/api/platform/users', $cToken, 'core-contractor');
$results['contractor_cannot_list_platform_users'] = in_array($ps, [401, 403], true);
$results['_status_contractor_on_platform'] = $ps;

[$ps2] = httpJson('GET', $base.'/api/platform/users', $sToken, 'core-consultant');
$results['consultant_cannot_list_platform_users'] = in_array($ps2, [401, 403], true);
$results['_status_consultant_on_platform'] = $ps2;

[$pi] = httpJson('GET', $base.'/api/iam/users', $ownerToken, 'core-contractor');
$results['platform_cannot_list_tenant_iam'] = in_array($pi, [401, 403], true);
$results['_status_platform_on_iam'] = $pi;

$newEmail = 'e4-eng-'.Str::lower(Str::random(6)).'@binaa.test';
[$createStatus] = httpJson('POST', $base.'/api/iam/users', $sToken, 'core-consultant', [
    'name' => 'E4 Engineer',
    'email' => $newEmail,
    'password' => $password,
    'password_confirmation' => $password,
    'role_ids' => [],
]);
$results['consultant_can_create_iam_user'] = $createStatus === 201;
$results['_status_consultant_create'] = $createStatus;

[$cs3, $cUsers2] = httpJson('GET', $base.'/api/iam/users', $cToken, 'core-contractor');
$results['contractor_still_cannot_see_new_consultant_user'] = ! collect($cUsers2['data'] ?? [])->pluck('email')->contains($newEmail);

$boolResults = array_filter($results, fn ($v, $k) => ! str_starts_with((string) $k, '_status_'), ARRAY_FILTER_USE_BOTH);
$pass = ! in_array(false, array_values($boolResults), true);
echo json_encode([
    'ok' => $pass,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($pass ? 0 : 1);
