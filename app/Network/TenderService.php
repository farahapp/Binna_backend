<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use App\Network\Events\TenderPublished;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

/**
 * TenderService — Public Capability for Network Tender Foundation management.
 *
 * NETWORK-002 — Network Tender Foundation
 *
 * Architectural constraints (NETWORK-ARCH-001 §4, §5, §12):
 *  - Backend authority: all business rules enforced here.
 *  - Audit: all state-changing operations logged via AuditService.
 *  - No Bid, Evaluation, Award workflow.
 *  - No ERP cross-schema coupling.
 *  - No billing/monetization (OD-006 Free V1).
 *  - Visibility access control enforced at service layer.
 *  - TenderPublished event dispatched on publish (NETWORK-ARCH-001 §5).
 */
class TenderService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a new Network Tender in draft status.
     *
     * @param array{
     *     publisher_profile_id: string,
     *     title: string,
     *     category: string,
     *     description?: string|null,
     *     region?: string|null,
     *     deadline?: string|null,
     *     budget_min?: float|null,
     *     budget_max?: float|null,
     *     specializations?: array|null,
     *     requirements?: array|null,
     *     participant_types?: array|null,
     *     visibility?: string,
     *     match_criteria?: array|null,
     * } $data
     * @throws \InvalidArgumentException if publisher_profile_id does not exist
     */
    public function createTender(array $data, string $actorId, string $tenantSlug): NetworkTender
    {
        // Validate publisher exists
        $publisher = NetworkProfile::find($data['publisher_profile_id']);
        if ($publisher === null) {
            throw new \InvalidArgumentException('Publisher network profile not found.');
        }

        $tender = NetworkTender::create([
            'publisher_profile_id' => $data['publisher_profile_id'],
            'title'                => $data['title'],
            'description'          => $data['description'] ?? null,
            'category'             => $data['category'],
            'region'               => $data['region'] ?? null,
            'deadline'             => $data['deadline'] ?? null,
            'budget_min'           => $data['budget_min'] ?? null,
            'budget_max'           => $data['budget_max'] ?? null,
            'specializations'      => $data['specializations'] ?? null,
            'requirements'         => $data['requirements'] ?? null,
            'participant_types'    => $data['participant_types'] ?? null,
            'status'               => 'draft',
            'visibility'           => $data['visibility'] ?? 'public',
            'match_criteria'       => $data['match_criteria'] ?? null,
            'managed_by_tenant_slug' => $tenantSlug,
        ]);

        $this->auditService->logEvent('network.tender.created', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkTender',
            'subject_id'   => $tender->id,
            'after'        => [
                'title'      => $tender->title,
                'category'   => $tender->category,
                'visibility' => $tender->visibility,
                'status'     => $tender->status,
            ],
        ]);

        return $tender;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get a Network Tender by ID.
     *
     * Access control:
     *  - public tenders: always accessible to authenticated users.
     *  - private tenders: only accessible to the publisher's tenant.
     *  - invited/matched: accessible to publisher; invited/matched access is
     *    foundation-level only (full invitation workflow is a later task).
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException if visibility denies access
     */
    public function getTender(string $id, string $viewerTenantSlug): NetworkTender
    {
        $tender = NetworkTender::findOrFail($id);

        $this->assertVisibilityAccess($tender, $viewerTenantSlug);

        return $tender;
    }

    /**
     * List Network Tenders with optional filters.
     * Only returns tenders accessible to the viewer (visibility-aware).
     *
     * @param array{
     *     status?: string,
     *     visibility?: string,
     *     category?: string,
     *     region?: string,
     *     publisher_profile_id?: string,
     * } $filters
     */
    public function listTenders(array $filters, string $viewerTenantSlug): LengthAwarePaginator
    {
        $query = NetworkTender::query();

        // Visibility filter: non-publisher tenants only see public tenders by default
        // Private/invited/matched tenders are only listed for the managing tenant
        $query->where(function ($q) use ($viewerTenantSlug) {
            $q->where('visibility', 'public')
              ->orWhere('managed_by_tenant_slug', $viewerTenantSlug);
        });

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['visibility'])) {
            $query->where('visibility', $filters['visibility']);
        }

        if (! empty($filters['category'])) {
            $query->byCategory($filters['category']);
        }

        if (! empty($filters['region'])) {
            $query->byRegion($filters['region']);
        }

        if (! empty($filters['publisher_profile_id'])) {
            $query->byPublisher($filters['publisher_profile_id']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Update allowed fields on a draft Tender.
     * Only draft tenders may be updated.
     *
     * @throws \RuntimeException if tender is not in draft status
     */
    public function updateTender(string $id, array $data, string $actorId, string $tenantSlug): NetworkTender
    {
        $tender = NetworkTender::findOrFail($id);

        if (! $tender->isDraft()) {
            throw new \RuntimeException('Only draft tenders can be updated.');
        }

        $allowed = [
            'title', 'description', 'category', 'region', 'deadline',
            'budget_min', 'budget_max', 'specializations', 'requirements',
            'participant_types', 'visibility', 'match_criteria',
        ];

        $before = $tender->only($allowed);
        $tender->fill(array_intersect_key($data, array_flip($allowed)));
        $tender->save();

        $this->auditService->logEvent('network.tender.updated', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkTender',
            'subject_id'   => $tender->id,
            'before'       => $before,
            'after'        => $tender->only($allowed),
        ]);

        return $tender->fresh();
    }

    // -------------------------------------------------------------------------
    // Lifecycle transitions
    // -------------------------------------------------------------------------

    /**
     * Publish a Tender (draft → published).
     * Dispatches TenderPublished domain event (NETWORK-ARCH-001 §5).
     *
     * @throws \RuntimeException if transition is not allowed
     */
    public function publishTender(string $id, string $actorId, string $tenantSlug): NetworkTender
    {
        $tender = NetworkTender::findOrFail($id);

        if (! $tender->canTransitionTo('published')) {
            throw new \RuntimeException(
                "Cannot publish tender with status '{$tender->status}'."
            );
        }

        $tender->status = 'published';
        $tender->save();

        $this->auditService->logEvent('network.tender.published', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkTender',
            'subject_id'   => $tender->id,
            'after'        => ['status' => 'published', 'visibility' => $tender->visibility],
        ]);

        // Dispatch TenderPublished domain event (NETWORK-ARCH-001 §5)
        Event::dispatch(new TenderPublished($tender));

        return $tender->fresh();
    }

    /**
     * Close a Tender (published → closed).
     *
     * @throws \RuntimeException if transition is not allowed
     */
    public function closeTender(string $id, string $actorId, string $tenantSlug): NetworkTender
    {
        $tender = NetworkTender::findOrFail($id);

        if (! $tender->canTransitionTo('closed')) {
            throw new \RuntimeException(
                "Cannot close tender with status '{$tender->status}'."
            );
        }

        $tender->status = 'closed';
        $tender->save();

        $this->auditService->logEvent('network.tender.closed', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkTender',
            'subject_id'   => $tender->id,
            'after'        => ['status' => 'closed'],
        ]);

        return $tender->fresh();
    }

    /**
     * Mark a Tender as awarded (closed → awarded).
     * Foundation only — no award selection or workflow logic.
     *
     * @throws \RuntimeException if transition is not allowed
     */
    public function markAwarded(string $id, string $actorId, string $tenantSlug): NetworkTender
    {
        $tender = NetworkTender::findOrFail($id);

        if (! $tender->canTransitionTo('awarded')) {
            throw new \RuntimeException(
                "Cannot mark tender as awarded with status '{$tender->status}'."
            );
        }

        $tender->status = 'awarded';
        $tender->save();

        $this->auditService->logEvent('network.tender.awarded', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkTender',
            'subject_id'   => $tender->id,
            'after'        => ['status' => 'awarded'],
        ]);

        return $tender->fresh();
    }

    // -------------------------------------------------------------------------
    // Visibility access guard
    // -------------------------------------------------------------------------

    /**
     * Assert that the viewer tenant has access to the given tender.
     *
     * Rules (NETWORK-ARCH-001 §4.2):
     *  - public: always accessible
     *  - private: only the managing tenant
     *  - invited: managing tenant (full invitation workflow is a later task)
     *  - matched: managing tenant (full matching engine is a later task)
     *
     * @throws \RuntimeException if access is denied
     */
    private function assertVisibilityAccess(NetworkTender $tender, string $viewerTenantSlug): void
    {
        if ($tender->isPublic()) {
            return; // always accessible
        }

        if ($tender->managed_by_tenant_slug === $viewerTenantSlug) {
            return; // managing tenant always has access
        }

        throw new \RuntimeException(
            "Access denied: tender visibility '{$tender->visibility}' restricts access."
        );
    }
}
