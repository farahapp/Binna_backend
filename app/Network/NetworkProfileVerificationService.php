<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use App\Notification\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class NetworkProfileVerificationService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
    ) {}

    public function submit(string $profileId, array $evidence, string $actorId, string $tenantSlug): NetworkProfileVerification
    {
        $profile = NetworkProfile::findOrFail($profileId);
        $this->assertOwner($profile, $tenantSlug);
        $this->validateEvidence($profile, $evidence);

        return DB::connection('mysql')->transaction(function () use ($profile, $evidence, $actorId, $tenantSlug): NetworkProfileVerification {
            $verification = NetworkProfileVerification::query()->lockForUpdate()->firstOrCreate(
                ['network_profile_id' => $profile->id],
                ['status' => 'unverified', 'current_attempt_number' => 0]
            );

            if (! in_array($verification->status, ['unverified', 'rejected'], true)) {
                throw new \DomainException('Verification can only be submitted from unverified or rejected state.');
            }

            $isResubmission = $verification->status === 'rejected';
            $attemptNumber = $verification->current_attempt_number + 1;

            NetworkProfileVerificationAttempt::create([
                'verification_id' => $verification->id,
                'network_profile_id' => $profile->id,
                'attempt_number' => $attemptNumber,
                'status' => 'pending',
                'submitted_by' => $actorId,
                'submitted_at' => now(),
                'evidence' => $evidence,
            ]);

            $verification->update([
                'status' => 'pending',
                'current_attempt_number' => $attemptNumber,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            $event = $isResubmission
                ? 'network.profile.verification.resubmitted'
                : 'network.profile.verification.requested';
            $this->audit($event, $actorId, $profile, $tenantSlug, ['attempt_number' => $attemptNumber]);
            $this->notify($tenantSlug, $actorId, $event, $profile->id, ['status' => 'pending']);

            return $verification->fresh();
        });
    }

    public function approve(string $profileId, string $reviewerId, string $tenantSlug): NetworkProfileVerification
    {
        return $this->review($profileId, 'verified', null, $reviewerId, $tenantSlug);
    }

    public function reject(string $profileId, string $reason, string $reviewerId, string $tenantSlug): NetworkProfileVerification
    {
        return $this->review($profileId, 'rejected', $reason, $reviewerId, $tenantSlug);
    }

    public function revoke(string $profileId, string $reason, string $reviewerId, string $tenantSlug): NetworkProfileVerification
    {
        $profile = NetworkProfile::findOrFail($profileId);

        return DB::connection('mysql')->transaction(function () use ($profile, $reason, $reviewerId, $tenantSlug): NetworkProfileVerification {
            $verification = NetworkProfileVerification::query()
                ->where('network_profile_id', $profile->id)->lockForUpdate()->firstOrFail();
            if ($verification->status !== 'verified') {
                throw new \DomainException('Only a verified profile can be revoked.');
            }

            $attempt = $verification->currentAttempt();
            $attempt?->update([
                'status' => 'revoked',
                'decision_reason' => $reason,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);
            $verification->update(['status' => 'unverified', 'reviewed_by' => $reviewerId, 'reviewed_at' => now()]);

            $event = 'network.profile.verification.revoked';
            $this->audit($event, $reviewerId, $profile, $tenantSlug, ['reason' => $reason]);
            $this->notify($tenantSlug, $this->ownerRecipient($profile), $event, $profile->id, ['status' => 'unverified', 'reason' => $reason]);

            return $verification->fresh();
        });
    }

    public function publicStatus(string $profileId): array
    {
        NetworkProfile::findOrFail($profileId);
        $status = NetworkProfileVerification::where('network_profile_id', $profileId)->value('status') ?? 'unverified';

        return ['network_profile_id' => $profileId, 'verification_status' => $status, 'verified' => $status === 'verified'];
    }

    public function ownerView(string $profileId, string $tenantSlug): array
    {
        $profile = NetworkProfile::findOrFail($profileId);
        $this->assertOwner($profile, $tenantSlug);
        $verification = NetworkProfileVerification::where('network_profile_id', $profileId)->first();
        if ($verification === null) {
            return $this->publicStatus($profileId) + ['attempts' => []];
        }

        return $verification->toArray() + ['attempts' => $verification->attempts()->orderBy('attempt_number')->get()->makeVisible(['decision_reason'])->makeHidden(['evidence', 'reviewed_by'])->toArray()];
    }

    public function reviewerView(string $profileId): array
    {
        $verification = NetworkProfileVerification::where('network_profile_id', $profileId)->firstOrFail();
        return $verification->toArray() + ['attempts' => $verification->attempts()->orderBy('attempt_number')->get()->makeVisible(['decision_reason', 'reviewed_by', 'evidence'])->toArray()];
    }

    public function pending(): LengthAwarePaginator
    {
        return NetworkProfileVerification::where('status', 'pending')->orderBy('updated_at')->paginate(20);
    }

    private function review(string $profileId, string $nextStatus, ?string $reason, string $reviewerId, string $tenantSlug): NetworkProfileVerification
    {
        $profile = NetworkProfile::findOrFail($profileId);

        return DB::connection('mysql')->transaction(function () use ($profile, $nextStatus, $reason, $reviewerId, $tenantSlug): NetworkProfileVerification {
            $verification = NetworkProfileVerification::query()
                ->where('network_profile_id', $profile->id)->lockForUpdate()->firstOrFail();
            if ($verification->status !== 'pending') {
                throw new \DomainException('Only a pending verification can be reviewed.');
            }

            $attempt = $verification->currentAttempt();
            if ($attempt === null || empty($attempt->evidence['business_registration'])) {
                throw new \DomainException('Required verification evidence is unavailable.');
            }

            $attempt->update([
                'status' => $nextStatus,
                'decision_reason' => $reason,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);
            $verification->update(['status' => $nextStatus, 'reviewed_by' => $reviewerId, 'reviewed_at' => now()]);

            $event = 'network.profile.verification.' . ($nextStatus === 'verified' ? 'approved' : 'rejected');
            $context = ['status' => $nextStatus];
            if ($reason !== null) {
                $context['reason'] = $reason;
            }
            $this->audit($event, $reviewerId, $profile, $tenantSlug, $context);
            $this->notify($tenantSlug, $this->ownerRecipient($profile), $event, $profile->id, $context);

            return $verification->fresh();
        });
    }

    private function validateEvidence(NetworkProfile $profile, array $evidence): void
    {
        if (empty($evidence['business_registration'])) {
            throw new \InvalidArgumentException('Legal/business registration evidence is required.');
        }
        if (in_array($profile->profile_type, ['engineer', 'contractor', 'main_contractor'], true)
            && empty($evidence['professional_license'])) {
            throw new \InvalidArgumentException('Professional classification/license evidence is required for this participant type.');
        }
    }

    private function assertOwner(NetworkProfile $profile, string $tenantSlug): void
    {
        if ($profile->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to manage this Network Profile.');
        }
    }

    private function ownerRecipient(NetworkProfile $profile): string
    {
        return $profile->id;
    }

    private function audit(string $event, string $actorId, NetworkProfile $profile, string $tenantSlug, array $after): void
    {
        $this->auditService->logEvent($event, $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'NetworkProfile',
            'subject_id' => $profile->id,
            'after' => $after,
        ]);
    }

    private function notify(string $tenantSlug, string $recipientId, string $type, string $profileId, array $data): void
    {
        $this->notificationService->send($tenantSlug, $recipientId, 'NetworkProfile', $type, 'database', ['network_profile_id' => $profileId] + $data);
    }
}
