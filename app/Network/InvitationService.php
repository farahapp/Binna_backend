<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use Illuminate\Support\Facades\DB;

class InvitationService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function invite(string $tenderId, string $inviteeProfileId, string $actorId, string $tenantSlug): NetworkTenderInvitation
    {
        $tender = NetworkTender::findOrFail($tenderId);

        if ($tender->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to invite to this Network Tender.');
        }

        $profile = NetworkProfile::findOrFail($inviteeProfileId);

        $existing = NetworkTenderInvitation::query()
            ->where('tender_id', $tenderId)
            ->where('invitee_network_profile_id', $inviteeProfileId)
            ->first();

        if ($existing !== null) {
            throw new \RuntimeException('Invitation already exists for this profile.');
        }

        $invitation = NetworkTenderInvitation::create([
            'tender_id' => $tenderId,
            'invitee_network_profile_id' => $inviteeProfileId,
            'invitee_tenant_slug' => $profile->managed_by_tenant_slug,
            'inviter_tenant_slug' => $tenantSlug,
            'status' => 'pending',
        ]);

        $this->auditService->logEvent('network.tender.invitation.created', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'NetworkTenderInvitation',
            'subject_id' => $invitation->id,
            'after' => [
                'tender_id' => $tenderId,
                'invitee_profile_id' => $inviteeProfileId,
                'status' => 'pending',
            ],
        ]);

        return $invitation;
    }

    public function listForTender(string $tenderId, string $tenantSlug)
    {
        $tender = NetworkTender::findOrFail($tenderId);
        if ($tender->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to view invitations for this Tender.');
        }

        return NetworkTenderInvitation::query()
            ->where('tender_id', $tenderId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function listMine(string $tenantSlug)
    {
        return NetworkTenderInvitation::query()
            ->where('invitee_tenant_slug', $tenantSlug)
            ->with('tender')
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function respond(string $invitationId, string $decision, string $actorId, string $tenantSlug, ?string $note = null): NetworkTenderInvitation
    {
        $invitation = NetworkTenderInvitation::findOrFail($invitationId);

        if ($invitation->invitee_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to respond to this invitation.');
        }

        if (! $invitation->isPending()) {
            throw new \RuntimeException('Invitation is not pending.');
        }

        if (! in_array($decision, ['accept', 'decline'], true)) {
            throw new \InvalidArgumentException('decision must be accept or decline.');
        }

        $invitation->status = $decision === 'accept' ? 'accepted' : 'declined';
        $invitation->responded_at = now();
        $invitation->response_note = $note;
        $invitation->save();

        $this->auditService->logEvent('network.tender.invitation.'.$decision, $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'NetworkTenderInvitation',
            'subject_id' => $invitation->id,
            'after' => ['status' => $invitation->status],
        ]);

        return $invitation->fresh();
    }

    public function hasAcceptedInvitation(string $tenderId, string $profileId): bool
    {
        return NetworkTenderInvitation::query()
            ->where('tender_id', $tenderId)
            ->where('invitee_network_profile_id', $profileId)
            ->where('status', 'accepted')
            ->exists();
    }

    public function hasAnyInvitation(string $tenderId, string $profileId): bool
    {
        return NetworkTenderInvitation::query()
            ->where('tender_id', $tenderId)
            ->where('invitee_network_profile_id', $profileId)
            ->whereIn('status', ['pending', 'accepted'])
            ->exists();
    }
}
