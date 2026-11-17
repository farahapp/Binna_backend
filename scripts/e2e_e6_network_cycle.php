<?php

declare(strict_types=1);

/**
 * Local E.6 Construction Business Network cycle (NOT Production).
 *
 * Covers:
 *  S1 public tender → bid → competitor bid confidentiality → eval → award → engagement
 *  S2 private tender visibility denial for non-publisher tenant
 *  S3 invited tender → invite → accept → bid; stranger cannot bid
 *  S4 project link-request create → decide (approve)
 *  S7 isolation: foreign award / foreign document / wrong-tenant bid peek
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

function httpMultipart(string $url, string $token, string $tenant, string $field, string $path, string $mime): array
{
    $ch = curl_init($url);
    $cfile = new CURLFile($path, $mime, basename($path));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer '.$token,
            'X-Tenant: '.$tenant,
        ],
        CURLOPT_POSTFIELDS => [$field => $cfile],
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
    if ($s !== 200 || ! is_array($b)) {
        return null;
    }

    return $b['token'] ?? $b['access_token'] ?? null;
}

function ensureProfile(string $base, string $token, string $tenant, string $type, string $name): ?string
{
    // Network profile list is cross-tenant directory — only reuse rows managed by THIS tenant.
    $id = null;
    $status = null;
    [$s, $b] = httpJson('GET', $base.'/api/v1/network/profiles?scope=mine&type='.$type, $token, $tenant);
    if ($s === 200 && is_array($b)) {
        $rows = $b['data'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['profile_type'] ?? '') !== $type) {
                    continue;
                }
                if (($row['managed_by_tenant_slug'] ?? '') !== $tenant) {
                    continue;
                }
                $id = $row['id'] ?? null;
                $status = $row['status'] ?? null;
                if ($status === 'active') {
                    return $id;
                }
                break;
            }
        }
    }
    if ($id === null) {
        [$cs, $cb] = httpJson('POST', $base.'/api/v1/network/profiles', $token, $tenant, [
            'company_name' => $name,
            'profile_type' => $type,
            'region' => 'Qatar',
        ]);
        if (in_array($cs, [200, 201], true) && is_array($cb)) {
            $id = $cb['data']['id'] ?? $cb['id'] ?? null;
            $status = $cb['data']['status'] ?? 'draft';
        }
    }
    if (is_string($id) && $status !== 'active') {
        [$as, $ab] = httpJson('PATCH', $base.'/api/v1/network/profiles/'.$id.'/activate', $token, $tenant);
        if (! in_array($as, [200, 201], true)) {
            // Surface activate failure into caller via null so profiles_ready fails loudly.
            return null;
        }
    }

    return $id;
}

$results = [];
$stamp = now()->format('YmdHis');

$ownerToken = ensureLogin($base, 'core-owner', 'owner@core-fixture.test', $password);
$contractorToken = ensureLogin($base, 'core-contractor', 'contractor@core-fixture.test', $password);
$consultantToken = ensureLogin($base, 'core-consultant', 'consultant@core-fixture.test', $password);

$results['owner_login'] = is_string($ownerToken);
$results['contractor_login'] = is_string($contractorToken);
$results['consultant_login'] = is_string($consultantToken);

if (! $ownerToken || ! $contractorToken || ! $consultantToken) {
    echo json_encode(['ok' => false, 'results' => $results, 'error' => 'login_failed'], JSON_PRETTY_PRINT).PHP_EOL;
    exit(1);
}

$ownerProfile = ensureProfile($base, $ownerToken, 'core-owner', 'owner', 'E6 Owner '.$stamp);
$contractorProfile = ensureProfile($base, $contractorToken, 'core-contractor', 'contractor', 'E6 Contractor '.$stamp);
$contractor2Profile = ensureProfile($base, $consultantToken, 'core-consultant', 'contractor', 'E6 Rival '.$stamp);
$results['profiles_ready'] = is_string($ownerProfile) && is_string($contractorProfile) && is_string($contractor2Profile);

// -------------------------------------------------------------------------
// S1 — Public tender cycle
// -------------------------------------------------------------------------
[$ts, $tb] = httpJson('POST', $base.'/api/v1/network/tenders', $ownerToken, 'core-owner', [
    'publisher_profile_id' => $ownerProfile,
    'title' => 'E6 Public Tender '.$stamp,
    'category' => 'construction',
    'visibility' => 'public',
    'region' => 'Qatar',
    'deadline' => now()->addDays(7)->toIso8601String(),
    'participant_types' => ['contractor', 'consultant'],
]);
$publicTenderId = is_array($tb) ? ($tb['data']['id'] ?? null) : null;
$results['s1_create_public'] = in_array($ts, [200, 201], true) && is_string($publicTenderId);
$results['_s1_create_status'] = $ts;

[$ps] = httpJson('PATCH', $base.'/api/v1/network/tenders/'.$publicTenderId.'/publish', $ownerToken, 'core-owner');
$results['s1_publish'] = $ps === 200;
$results['_s1_publish_status'] = $ps;

[$ls, $lb] = httpJson('GET', $base.'/api/v1/network/tenders', $contractorToken, 'core-contractor');
$listedIds = [];
if ($ls === 200 && is_array($lb)) {
    foreach (($lb['data'] ?? []) as $row) {
        if (is_array($row) && isset($row['id'])) {
            $listedIds[] = $row['id'];
        }
    }
}
$results['s1_contractor_sees_public'] = in_array($publicTenderId, $listedIds, true);

[$showS, $showB] = httpJson('GET', $base.'/api/v1/network/tenders/'.$publicTenderId, $contractorToken, 'core-contractor');
$results['s1_contractor_open_detail'] = $showS === 200;

[$bs, $bb] = httpJson('POST', $base.'/api/v1/network/bids', $contractorToken, 'core-contractor', [
    'tender_id' => $publicTenderId,
    'contractor_profile_id' => $contractorProfile,
    'content' => ['amount' => 100000, 'currency' => 'QAR', 'note' => 'E6 bid A'],
]);
$bidA = is_array($bb) ? ($bb['data']['id'] ?? null) : null;
$results['s1_contractor_bid'] = in_array($bs, [200, 201], true) && is_string($bidA);
$results['_s1_bid_status'] = $bs;
$results['_s1_bid_error'] = is_array($bb) ? ($bb['error'] ?? $bb['message'] ?? null) : null;

// Rival bid
[$bs2, $bb2] = httpJson('POST', $base.'/api/v1/network/bids', $consultantToken, 'core-consultant', [
    'tender_id' => $publicTenderId,
    'contractor_profile_id' => $contractor2Profile,
    'content' => ['amount' => 110000, 'currency' => 'QAR', 'note' => 'E6 bid B'],
]);
$bidB = is_array($bb2) ? ($bb2['data']['id'] ?? null) : null;
$results['s1_rival_bid'] = in_array($bs2, [200, 201], true) && is_string($bidB);
$results['_s1_rival_bid_status'] = $bs2;
$results['_s1_rival_bid_error'] = is_array($bb2) ? ($bb2['error'] ?? $bb2['message'] ?? null) : null;
$results['_s1_rival_profile'] = $contractor2Profile;

// Competitor cannot read rival bid
if (is_string($bidB)) {
    [$peekS] = httpJson('GET', $base.'/api/v1/network/bids/'.$bidB, $contractorToken, 'core-contractor');
    $results['s1_competitor_cannot_read_rival_bid'] = in_array($peekS, [403, 404], true);
    $results['_s1_peek_status'] = $peekS;
} else {
    $results['s1_competitor_cannot_read_rival_bid'] = false;
    $results['_s1_peek_status'] = 'no_rival_bid';
}

// Owner closes → evaluates → awards
[$cs] = httpJson('PATCH', $base.'/api/v1/network/tenders/'.$publicTenderId.'/close', $ownerToken, 'core-owner');
$results['s1_close'] = $cs === 200;
$results['_s1_close_status'] = $cs;

[$es, $eb] = httpJson('POST', $base.'/api/v1/network/tenders/'.$publicTenderId.'/evaluation', $ownerToken, 'core-owner');
$evalId = is_array($eb) ? ($eb['id'] ?? $eb['data']['id'] ?? null) : null;
$results['s1_start_eval'] = in_array($es, [200, 201], true) && is_string($evalId);
$results['_s1_eval_status'] = $es;

[$ecs] = httpJson('PATCH', $base.'/api/v1/network/evaluations/'.$evalId.'/complete', $ownerToken, 'core-owner');
$results['s1_complete_eval'] = $ecs === 200;
$results['_s1_complete_eval_status'] = $ecs;

[$as, $ab] = httpJson('POST', $base.'/api/v1/network/tenders/'.$publicTenderId.'/award', $ownerToken, 'core-owner', [
    'winning_bid_id' => $bidA,
    'reason' => 'E6 lowest responsible bid',
]);
$awardId = is_array($ab) ? ($ab['id'] ?? $ab['data']['id'] ?? null) : null;
$results['s1_award'] = in_array($as, [200, 201], true) && is_string($awardId);
$results['_s1_award_status'] = $as;
$results['_s1_award_error'] = is_array($ab) ? ($ab['error'] ?? $ab['message'] ?? null) : null;

$engagementExists = false;
if (is_string($awardId)) {
    $engagementExists = DB::connection('mysql')->table('engagements')
        ->where('network_award_id', $awardId)
        ->exists();
}
$results['s1_engagement_created'] = $engagementExists;

// Unauthorized award attempt (contractor)
[$uas] = httpJson('POST', $base.'/api/v1/network/tenders/'.$publicTenderId.'/award', $contractorToken, 'core-contractor', [
    'winning_bid_id' => $bidA,
]);
$results['s7_contractor_cannot_award'] = in_array($uas, [403, 404, 422], true);
$results['_s7_award_status'] = $uas;

// -------------------------------------------------------------------------
// S2 — Private tender
// -------------------------------------------------------------------------
[$pts, $ptb] = httpJson('POST', $base.'/api/v1/network/tenders', $ownerToken, 'core-owner', [
    'publisher_profile_id' => $ownerProfile,
    'title' => 'E6 Private Tender '.$stamp,
    'category' => 'construction',
    'visibility' => 'private',
    'region' => 'Qatar',
    'deadline' => now()->addDays(5)->toIso8601String(),
    'participant_types' => ['contractor'],
]);
$privateTenderId = is_array($ptb) ? ($ptb['data']['id'] ?? null) : null;
$results['s2_create_private'] = in_array($pts, [200, 201], true) && is_string($privateTenderId);
httpJson('PATCH', $base.'/api/v1/network/tenders/'.$privateTenderId.'/publish', $ownerToken, 'core-owner');

[$pls, $plb] = httpJson('GET', $base.'/api/v1/network/tenders', $contractorToken, 'core-contractor');
$privateListed = false;
if ($pls === 200 && is_array($plb)) {
    foreach (($plb['data'] ?? []) as $row) {
        if (($row['id'] ?? null) === $privateTenderId) {
            $privateListed = true;
        }
    }
}
$results['s2_contractor_does_not_list_private'] = $privateListed === false;

[$pshow] = httpJson('GET', $base.'/api/v1/network/tenders/'.$privateTenderId, $contractorToken, 'core-contractor');
$results['s2_contractor_cannot_open_private'] = in_array($pshow, [403, 404], true);
$results['_s2_show_status'] = $pshow;

[$pbidS] = httpJson('POST', $base.'/api/v1/network/bids', $contractorToken, 'core-contractor', [
    'tender_id' => $privateTenderId,
    'contractor_profile_id' => $contractorProfile,
    'content' => ['amount' => 1],
]);
$results['s2_contractor_cannot_bid_private'] = in_array($pbidS, [403, 404, 422], true);
$results['_s2_bid_status'] = $pbidS;

// -------------------------------------------------------------------------
// S3 — Invited tender
// -------------------------------------------------------------------------
[$its, $itb] = httpJson('POST', $base.'/api/v1/network/tenders', $ownerToken, 'core-owner', [
    'publisher_profile_id' => $ownerProfile,
    'title' => 'E6 Invited Tender '.$stamp,
    'category' => 'construction',
    'visibility' => 'invited',
    'region' => 'Qatar',
    'deadline' => now()->addDays(5)->toIso8601String(),
    'participant_types' => ['contractor'],
]);
$invitedTenderId = is_array($itb) ? ($itb['data']['id'] ?? null) : null;
$results['s3_create_invited'] = in_array($its, [200, 201], true) && is_string($invitedTenderId);
httpJson('PATCH', $base.'/api/v1/network/tenders/'.$invitedTenderId.'/publish', $ownerToken, 'core-owner');

[$ivs, $ivb] = httpJson('POST', $base.'/api/v1/network/tenders/'.$invitedTenderId.'/invitations', $ownerToken, 'core-owner', [
    'invitee_network_profile_id' => $contractorProfile,
]);
$inviteId = is_array($ivb) ? ($ivb['data']['id'] ?? $ivb['id'] ?? null) : null;
$results['s3_invite'] = in_array($ivs, [200, 201], true) && is_string($inviteId);
$results['_s3_invite_status'] = $ivs;

[$mineS, $mineB] = httpJson('GET', $base.'/api/v1/network/invitations/mine', $contractorToken, 'core-contractor');
$sawInvite = false;
if ($mineS === 200 && is_array($mineB)) {
    foreach (($mineB['data'] ?? []) as $row) {
        if (($row['id'] ?? null) === $inviteId) {
            $sawInvite = true;
        }
    }
}
$results['s3_contractor_sees_invite'] = $sawInvite;

// Stranger (consultant) cannot accept contractor invite
[$strangerAccept] = httpJson(
    'PATCH',
    $base.'/api/v1/network/invitations/'.$inviteId.'/respond',
    $consultantToken,
    'core-consultant',
    ['decision' => 'accept']
);
$results['s3_stranger_cannot_accept'] = in_array($strangerAccept, [403, 404, 422], true);

[$accS] = httpJson(
    'PATCH',
    $base.'/api/v1/network/invitations/'.$inviteId.'/respond',
    $contractorToken,
    'core-contractor',
    ['decision' => 'accept']
);
$results['s3_contractor_accept'] = $accS === 200;
$results['_s3_accept_status'] = $accS;

[$ibS, $ibB] = httpJson('POST', $base.'/api/v1/network/bids', $contractorToken, 'core-contractor', [
    'tender_id' => $invitedTenderId,
    'contractor_profile_id' => $contractorProfile,
    'content' => ['amount' => 90000, 'currency' => 'QAR'],
]);
$results['s3_invitee_can_bid'] = in_array($ibS, [200, 201], true);
$results['_s3_bid_status'] = $ibS;

[$strangerBid] = httpJson('POST', $base.'/api/v1/network/bids', $consultantToken, 'core-consultant', [
    'tender_id' => $invitedTenderId,
    'contractor_profile_id' => $contractor2Profile,
    'content' => ['amount' => 88000],
]);
$results['s3_stranger_cannot_bid'] = in_array($strangerBid, [403, 404, 422], true);
$results['_s3_stranger_bid_status'] = $strangerBid;

// -------------------------------------------------------------------------
// S4 — Project link request
// -------------------------------------------------------------------------
[$is1, $ib1] = httpJson('POST', $base.'/api/v1/core/project-identities', $ownerToken, 'core-owner', [
    'name' => 'E6 Link Source '.$stamp,
    'org_type' => 'owner',
    'region' => 'Qatar',
]);
$srcId = is_array($ib1) ? ($ib1['data']['id'] ?? null) : null;
[$is2, $ib2] = httpJson('POST', $base.'/api/v1/core/project-identities', $ownerToken, 'core-owner', [
    'name' => 'E6 Link Target '.$stamp,
    'org_type' => 'owner',
    'region' => 'Qatar',
]);
$tgtId = is_array($ib2) ? ($ib2['data']['id'] ?? null) : null;

[$lrs, $lrb] = httpJson('POST', $base.'/api/v1/core/project-link-requests', $ownerToken, 'core-owner', [
    'source_project_identity_id' => $srcId,
    'target_project_identity_id' => $tgtId,
    'requester_org_type' => 'owner',
    'match_type' => 'manual',
]);
$linkId = is_array($lrb) ? ($lrb['data']['id'] ?? null) : null;
$results['s4_create_link_request'] = in_array($lrs, [200, 201], true) && is_string($linkId);
$results['_s4_create_status'] = $lrs;

// Contractor cannot decide owner's link request
[$foreignDecide] = httpJson(
    'PATCH',
    $base.'/api/v1/core/project-link-requests/'.$linkId.'/decide',
    $contractorToken,
    'core-contractor',
    ['decision' => 'approve']
);
$results['s4_foreign_cannot_decide'] = in_array($foreignDecide, [403, 404, 422], true);
$results['_s4_foreign_status'] = $foreignDecide;

[$ds, $db] = httpJson(
    'PATCH',
    $base.'/api/v1/core/project-link-requests/'.$linkId.'/decide',
    $ownerToken,
    'core-owner',
    ['decision' => 'approve', 'reason' => 'E6 merge test']
);
$results['s4_owner_approve'] = $ds === 200 && (($db['data']['status'] ?? '') === 'approved');
$results['_s4_decide_status'] = $ds;

// Link does not auto-grant contractor access to owner properties
[$propS, $propB] = httpJson('GET', $base.'/api/v1/core/properties', $ownerToken, 'core-owner');
$ownerPropId = null;
if ($propS === 200 && is_array($propB)) {
    $ownerPropId = $propB['data'][0]['id'] ?? null;
}
if ($ownerPropId) {
    [$xprop] = httpJson('GET', $base.'/api/v1/core/properties/'.$ownerPropId, $contractorToken, 'core-contractor');
    $results['s4_link_no_property_grant'] = in_array($xprop, [403, 404], true) || $xprop !== 200;
    $results['_s4_prop_status'] = $xprop;
} else {
    $results['s4_link_no_property_grant'] = true;
    $results['_s4_prop_status'] = 'no_property';
}

// -------------------------------------------------------------------------
// Documents create + upload + cross-tenant denial
// -------------------------------------------------------------------------
[$docs, $docb] = httpJson('POST', $base.'/api/v1/documents', $ownerToken, 'core-owner', [
    'title' => 'E6 Doc '.$stamp,
    'description' => 'network attachment test',
    'status' => 'draft',
]);
$docId = is_array($docb) ? ($docb['id'] ?? $docb['data']['id'] ?? null) : null;
$results['doc_create'] = in_array($docs, [200, 201], true) && is_string($docId);
$results['_doc_create_status'] = $docs;

$tmpPdf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'e6-'.$stamp.'.pdf';
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
[$ups, $upb] = httpMultipart(
    $base.'/api/v1/documents/'.$docId.'/upload',
    $ownerToken,
    'core-owner',
    'file',
    $tmpPdf,
    'application/pdf'
);
@unlink($tmpPdf);
$results['doc_upload'] = $ups === 200;
$results['_doc_upload_status'] = $ups;
$results['_doc_upload_error'] = is_array($upb) ? ($upb['message'] ?? $upb['error'] ?? null) : null;

[$xdoc] = httpJson('GET', $base.'/api/v1/documents/'.$docId, $contractorToken, 'core-contractor');
$results['s7_contractor_cannot_read_owner_doc'] = in_array($xdoc, [403, 404], true);
$results['_s7_doc_status'] = $xdoc;

[$xdl] = httpJson('GET', $base.'/api/v1/documents/'.$docId.'/download', $contractorToken, 'core-contractor');
$results['s7_contractor_cannot_download_owner_doc'] = in_array($xdl, [403, 404], true);
$results['_s7_dl_status'] = $xdl;

// -------------------------------------------------------------------------
$bool = array_filter($results, fn ($v, $k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_BOTH);
$ok = ! in_array(false, array_values($bool), true);

echo json_encode([
    'ok' => $ok,
    'public_tender_id' => $publicTenderId,
    'award_id' => $awardId ?? null,
    'link_request_id' => $linkId ?? null,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($ok ? 0 : 1);
