<?php

declare(strict_types=1);

/**
 * Local E.2 registration journeys (contractor + consultant) against shared MySQL.
 * No Production. Does not provision tenants automatically.
 *
 * Usage (Laragon PHP 8.4 from backend/):
 *   php scripts/e2e_e2_registration_journeys.php
 */

use App\Foundation\PlatformUser;
use App\Mail\RegistrationStatusChangedMail;
use App\Registration\Applicant;
use App\Registration\RegistrationAuditService;
use App\Registration\RegistrationTokenIssuer;
use App\Registration\TenantRegistrationRequest;
use App\Registration\TenantRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('local', 'testing')) {
    fwrite(STDERR, "REFUSED: only local/testing\n");
    exit(1);
}

Mail::fake();
$svc = new TenantRegistrationService(new RegistrationAuditService());
$stamp = now()->format('YmdHis');
$results = [];

$actor = PlatformUser::on('mysql')->where('email', 'platform-smoke@binaa.test')->first();
if (! $actor) {
    $actor = PlatformUser::on('mysql')->create([
        'name' => 'E2 Platform Reviewer',
        'email' => 'e2-platform-reviewer@binaa.test',
        'password' => Hash::make(Str::random(24)),
        'is_active' => true,
    ]);
}

function journey(
    TenantRegistrationService $svc,
    PlatformUser $actor,
    string $stamp,
    string $activity,
    array $identifiers,
): array {
    $email = "e2-{$activity}-{$stamp}@binaa.test";
    $applicant = Applicant::on('mysql')->firstOrCreate(
        ['email' => $email],
        ['email_verified_at' => now()],
    );
    $applicant->forceFill(['email_verified_at' => now()])->save();

    $draft = $svc->createDraft([
        'name' => "E2 {$activity} Co {$stamp}",
        'legal_type' => 'organization',
        'activity_type' => $activity,
        'responsible_name' => 'E2 Responsible',
        'phone' => '+97450000000',
        'identifiers' => $identifiers,
        'applicant_email' => $email,
    ], $applicant);

    $submitted = $svc->submit($draft, 'applicant', $applicant->id);
    $under = $svc->review($submitted, $actor, 'start_review', "E2 start {$activity}");
    $needs = $svc->review($under, $actor, 'needs_information', "E2 needs docs for {$activity}");

    $updated = $svc->updateDraft($needs, [
        'name' => "E2 {$activity} Co {$stamp} Updated",
        'legal_type' => 'organization',
        'activity_type' => $activity,
        'responsible_name' => 'E2 Responsible',
        'phone' => '+97450000001',
        'identifiers' => $identifiers,
        'applicant_email' => $email,
    ], 'applicant', $applicant->id);

    $resubmitted = $svc->submit($updated, 'applicant', $applicant->id);
    $under2 = $svc->review($resubmitted, $actor, 'start_review', "E2 re-review {$activity}");
    $approved = $svc->review(
        $under2,
        $actor,
        'approve',
        "E2 approve {$activity}",
        'E2-VERREF-'.$stamp.'-'.$activity,
    );

    $otherEmail = "e2-other-{$activity}-{$stamp}@binaa.test";
    $other = Applicant::on('mysql')->firstOrCreate(
        ['email' => $otherEmail],
        ['email_verified_at' => now()],
    );
    $other->forceFill(['email_verified_at' => now()])->save();
    $foreignVisible = TenantRegistrationRequest::on('mysql')
        ->where('applicant_id', $other->id)
        ->whereKey($approved->id)
        ->exists();
    $ownedVisible = TenantRegistrationRequest::on('mysql')
        ->where('applicant_id', $applicant->id)
        ->whereKey($approved->id)
        ->exists();

    $tokenPair = app(RegistrationTokenIssuer::class)->issue(
        'registration_applicant_tokens',
        'applicant_id',
        (int) $applicant->id,
    );

    return [
        'activity' => $activity,
        'request_id' => $approved->id,
        'request_number' => $approved->request_number,
        'final_status' => $approved->status,
        'tenant_id' => $approved->tenant_id,
        'owned_visible' => $ownedVisible,
        'foreign_visible' => $foreignVisible,
        'applicant_token_issued' => is_string($tokenPair['token'] ?? null) && $tokenPair['token'] !== '',
    ];
}

$contractorCr = 'E2C'.$stamp;
$consultantCr = 'E2S'.$stamp;
$license = 'E2PL'.$stamp;

$results['contractor'] = journey($svc, $actor, $stamp, 'contractor', [
    ['type' => 'cr', 'number' => $contractorCr],
]);
$results['consultant'] = journey($svc, $actor, $stamp, 'consultant', [
    ['type' => 'cr', 'number' => $consultantCr],
    ['type' => 'professional_license', 'number' => $license],
]);

// Reject path smoke (separate contractor draft)
$rejectEmail = "e2-reject-{$stamp}@binaa.test";
$rejectApplicant = Applicant::on('mysql')->firstOrCreate(
    ['email' => $rejectEmail],
    ['email_verified_at' => now()],
);
$rejectApplicant->forceFill(['email_verified_at' => now()])->save();
$rejectDraft = $svc->createDraft([
    'name' => "E2 Reject Co {$stamp}",
    'legal_type' => 'organization',
    'activity_type' => 'contractor',
    'responsible_name' => 'E2 Reject',
    'identifiers' => [['type' => 'cr', 'number' => 'E2R'.$stamp]],
    'applicant_email' => $rejectEmail,
], $rejectApplicant);
$rejectSubmitted = $svc->submit($rejectDraft, 'applicant', $rejectApplicant->id);
$rejectUnder = $svc->review($rejectSubmitted, $actor, 'start_review', 'E2 reject start');
$rejected = $svc->review($rejectUnder, $actor, 'reject', 'E2 reject reason: incomplete license pack');
$results['reject'] = [
    'request_id' => $rejected->id,
    'final_status' => $rejected->status,
];

$mailCount = Mail::sent(RegistrationStatusChangedMail::class)->count();
$platformListed = TenantRegistrationRequest::on('mysql')
    ->whereIn('id', [
        $results['contractor']['request_id'],
        $results['consultant']['request_id'],
        $results['reject']['request_id'],
    ])
    ->count();

$pass =
    $results['contractor']['final_status'] === 'approved_pending_provisioning'
    && $results['consultant']['final_status'] === 'approved_pending_provisioning'
    && $results['reject']['final_status'] === 'rejected'
    && $results['contractor']['tenant_id'] === null
    && $results['consultant']['tenant_id'] === null
    && $results['contractor']['owned_visible'] === true
    && $results['consultant']['owned_visible'] === true
    && $results['contractor']['foreign_visible'] === false
    && $results['consultant']['foreign_visible'] === false
    && $mailCount >= 8
    && $platformListed === 3;

echo json_encode([
    'ok' => $pass,
    'stamp' => $stamp,
    'status_mails_sent' => $mailCount,
    'platform_listed' => $platformListed,
    'journeys' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($pass ? 0 : 1);
