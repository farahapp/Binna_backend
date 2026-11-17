<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;

class ClarificationService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function ask(array $data, string $actorId, string $tenantSlug): NetworkClarification
    {
        $tender = NetworkTender::findOrFail($data['tender_id']);

        // Publisher or accepted invitee / public tender participant may ask.
        $isPublisher = $tender->managed_by_tenant_slug === $tenantSlug;
        if (! $isPublisher && ! $tender->isPublic()) {
            $allowed = NetworkTenderInvitation::query()
                ->where('tender_id', $tender->id)
                ->where('invitee_tenant_slug', $tenantSlug)
                ->whereIn('status', ['pending', 'accepted'])
                ->exists();
            if (! $allowed && $tender->managed_by_tenant_slug !== $tenantSlug) {
                throw new \RuntimeException('You are not authorized to ask clarifications on this Tender.');
            }
        }

        $clarification = NetworkClarification::create([
            'tender_id' => $tender->id,
            'bid_id' => $data['bid_id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'asked_by_tenant_slug' => $tenantSlug,
            'asked_by_profile_id' => $data['asked_by_profile_id'] ?? null,
            'directed_to_tenant_slug' => $data['directed_to_tenant_slug'] ?? $tender->managed_by_tenant_slug,
            'question' => $data['question'],
            'status' => 'open',
        ]);

        $this->auditService->logEvent('network.clarification.asked', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'NetworkClarification',
            'subject_id' => $clarification->id,
            'after' => ['tender_id' => $tender->id, 'status' => 'open'],
        ]);

        return $clarification;
    }

    public function answer(string $id, string $response, string $actorId, string $tenantSlug): NetworkClarification
    {
        $clarification = NetworkClarification::findOrFail($id);
        $tender = NetworkTender::findOrFail($clarification->tender_id);

        $canAnswer = $tender->managed_by_tenant_slug === $tenantSlug
            || $clarification->directed_to_tenant_slug === $tenantSlug;

        if (! $canAnswer) {
            throw new \RuntimeException('You are not authorized to answer this clarification.');
        }

        if ($clarification->status === 'closed') {
            throw new \RuntimeException('Clarification is closed.');
        }

        $clarification->response = $response;
        $clarification->status = 'answered';
        $clarification->answered_at = now();
        $clarification->save();

        $this->auditService->logEvent('network.clarification.answered', $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'NetworkClarification',
            'subject_id' => $clarification->id,
            'after' => ['status' => 'answered'],
        ]);

        return $clarification->fresh();
    }

    public function listForTender(string $tenderId, string $tenantSlug)
    {
        $tender = NetworkTender::findOrFail($tenderId);

        $isPublisher = $tender->managed_by_tenant_slug === $tenantSlug;
        $isParty = NetworkTenderInvitation::query()
            ->where('tender_id', $tenderId)
            ->where('invitee_tenant_slug', $tenantSlug)
            ->exists();

        if (! $isPublisher && ! $isParty && ! $tender->isPublic()) {
            throw new \RuntimeException('You are not authorized to view clarifications for this Tender.');
        }

        return NetworkClarification::query()
            ->where('tender_id', $tenderId)
            ->orderBy('created_at')
            ->get();
    }
}
