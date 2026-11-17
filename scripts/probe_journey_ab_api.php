<?php

declare(strict_types=1);

/**
 * Local-only Journey A/B API bridge for browser E2E gaps (tender/bid/award/invite).
 * Uses fixture PATs; prints status codes only (no secrets).
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

$password = getenv('BINAA2_FIXTURE_PASSWORD') ?: 'CoreFix#LocalTest99Aa1';
$base = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8000', '/');

function connectSchema(string $schema): void
{
    $base = config('database.connections.mysql');
    config(['database.connections.tenant' => array_merge($base, ['database' => $schema])]);
    DB::purge('tenant');
    DB::reconnect('tenant');
}

function tokenFor(string $slug, string $email, string $password): ?string
{
    $tenant = Tenant::on('mysql')->where('slug', $slug)->first();
    if (! $tenant || empty($tenant->schema_name)) {
        return null;
    }
    connectSchema($tenant->schema_name);
    $user = User::on('tenant')->where('email', $email)->first();
    if (! $user || ! Hash::check($password, $user->password)) {
        return null;
    }
    if (method_exists($user, 'tokens')) {
        $user->tokens()->where('name', 'journey-ab')->delete();
    }
    return $user->createToken('journey-ab')->plainTextToken;
}

function api(string $method, string $path, string $token, string $tenant, array $json = []): array
{
    global $base;
    $req = Http::withToken($token)
        ->withHeaders(['X-Tenant' => $tenant, 'Accept' => 'application/json'])
        ->baseUrl($base);
    $resp = match (strtoupper($method)) {
        'GET' => $req->get($path),
        'POST' => $req->post($path, $json),
        'PATCH' => $req->patch($path, $json),
        default => throw new InvalidArgumentException($method),
    };

    $json = $resp->json();
    if ($resp->status() >= 400) {
        $safe = is_array($json) ? array_intersect_key($json, array_flip(['message', 'errors', 'error'])) : null;
        echo 'ERR '.$method.' '.$path.' status='.$resp->status().' body='.json_encode($safe, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    return ['status' => $resp->status(), 'json' => $json];
}

$ownerTok = tokenFor('core-owner', 'owner@core-fixture.test', $password);
$consTok = tokenFor('core-consultant', 'consultant@core-fixture.test', $password);
$ctrTok = tokenFor('core-contractor', 'contractor@core-fixture.test', $password);

if (! $ownerTok || ! $consTok || ! $ctrTok) {
    echo 'TOKEN_FAIL'.PHP_EOL;
    exit(1);
}
echo 'TOKENS_OK'.PHP_EOL;

// Ensure network profiles exist (publisher/bidder)
$ownerProfile = api('POST', '/api/v1/network/profiles', $ownerTok, 'core-owner', [
    'company_name' => 'Journey Owner Profile',
    'profile_type' => 'owner',
    'region' => 'Qatar',
]);
echo 'OWNER_PROFILE='.$ownerProfile['status'].PHP_EOL;
$ownerProfileId = $ownerProfile['json']['data']['id'] ?? ($ownerProfile['json']['id'] ?? null);
if ($ownerProfileId) {
    $act = api('PATCH', "/api/v1/network/profiles/{$ownerProfileId}/activate", $ownerTok, 'core-owner');
    echo 'OWNER_PROFILE_ACTIVATE='.$act['status'].PHP_EOL;
}

$ctrProfile = api('POST', '/api/v1/network/profiles', $ctrTok, 'core-contractor', [
    'company_name' => 'Journey Contractor Profile',
    'profile_type' => 'contractor',
    'region' => 'Qatar',
]);
echo 'CTR_PROFILE='.$ctrProfile['status'].PHP_EOL;
$ctrProfileId = $ctrProfile['json']['data']['id'] ?? ($ctrProfile['json']['id'] ?? null);
if ($ctrProfileId) {
    $act = api('PATCH', "/api/v1/network/profiles/{$ctrProfileId}/activate", $ctrTok, 'core-contractor');
    echo 'CTR_PROFILE_ACTIVATE='.$act['status'].PHP_EOL;
}

$tender = api('POST', '/api/v1/network/tenders', $ownerTok, 'core-owner', [
    'publisher_profile_id' => $ownerProfileId,
    'title' => 'JourneyA Tender Lusail '.date('His'),
    'category' => 'construction',
    'description' => 'Browser Journey A tender',
    'region' => 'Qatar',
    'visibility' => 'public',
    'participant_types' => ['contractor'],
    'deadline' => now()->addDays(14)->toDateString(),
]);
echo 'TENDER_CREATE='.$tender['status'].PHP_EOL;
$tenderId = $tender['json']['data']['id'] ?? null;

if ($tenderId) {
    $pub = api('PATCH', "/api/v1/network/tenders/{$tenderId}/publish", $ownerTok, 'core-owner');
    echo 'TENDER_PUBLISH='.$pub['status'].PHP_EOL;
}

$invite = null;
if ($tenderId && $ctrProfileId) {
    $invite = api('POST', "/api/v1/network/tenders/{$tenderId}/invitations", $ownerTok, 'core-owner', [
        'invitee_network_profile_id' => $ctrProfileId,
    ]);
    echo 'INVITE='.$invite['status'].PHP_EOL;
}

$bid = null;
if ($tenderId && $ctrProfileId) {
    $bid = api('POST', '/api/v1/network/bids', $ctrTok, 'core-contractor', [
        'tender_id' => $tenderId,
        'contractor_profile_id' => $ctrProfileId,
        'content' => [
            'amount' => 150000,
            'currency' => 'QAR',
            'summary' => 'JourneyA bid',
        ],
    ]);
    echo 'BID='.$bid['status'].PHP_EOL;
}
$bidId = $bid['json']['data']['id'] ?? null;

$award = null;
if ($tenderId && $bidId) {
    $close = api('PATCH', "/api/v1/network/tenders/{$tenderId}/close", $ownerTok, 'core-owner');
    echo 'TENDER_CLOSE='.$close['status'].PHP_EOL;
    $eval = api('POST', "/api/v1/network/tenders/{$tenderId}/evaluation", $ownerTok, 'core-owner');
    echo 'EVAL_START='.$eval['status'].PHP_EOL;
    $evalId = $eval['json']['id'] ?? ($eval['json']['data']['id'] ?? null);
    if ($evalId) {
        $done = api('PATCH', "/api/v1/network/evaluations/{$evalId}/complete", $ownerTok, 'core-owner');
        echo 'EVAL_COMPLETE='.$done['status'].PHP_EOL;
    }
    $award = api('POST', "/api/v1/network/tenders/{$tenderId}/award", $ownerTok, 'core-owner', [
        'winning_bid_id' => $bidId,
    ]);
    echo 'AWARD='.$award['status'].PHP_EOL;
}

// Journey B: contractor-originated project identity (private/unverified)
$bIdentity = api('POST', '/api/v1/core/project-identities', $ctrTok, 'core-contractor', [
    'name' => 'JourneyB Contractor Project '.date('His'),
    'org_type' => 'contractor',
    'region' => 'Qatar',
    'description' => 'Contractor-originated private project',
]);
echo 'B_IDENTITY='.$bIdentity['status'].PHP_EOL;
$bId = $bIdentity['json']['data']['id'] ?? null;

$inviteOwner = null;
if ($bId) {
    $inviteOwner = api('POST', "/api/v1/core/project-identities/{$bId}/participations", $ctrTok, 'core-contractor', [
        'participant_tenant_slug' => 'core-owner',
        'organization_type' => 'owner',
        'project_role' => 'owner',
        'status' => 'invited',
    ]);
    echo 'B_INVITE_OWNER='.$inviteOwner['status'].PHP_EOL;
}

$inviteCons = null;
if ($bId) {
    $inviteCons = api('POST', "/api/v1/core/project-identities/{$bId}/participations", $ctrTok, 'core-contractor', [
        'participant_tenant_slug' => 'core-consultant',
        'organization_type' => 'consultant',
        'project_role' => 'consultant',
        'status' => 'invited',
    ]);
    echo 'B_INVITE_CONS='.$inviteCons['status'].PHP_EOL;
}

echo 'JOURNEY_AB_API_DONE tender='.($tenderId ?: 'null').' bid='.($bidId ?: 'null').' b_identity='.($bId ?: 'null').PHP_EOL;
