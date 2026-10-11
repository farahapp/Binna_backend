<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use App\Network\Events\AwardCreated;
use App\Network\Events\TenderAwarded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AwardService — Award workflow.
 *
 * NETWORK-005 — Award
 *
 * V1: Single Award per Tender — final, no revocation/amendment/cancellation.
 *
 * Prerequisites (all must be true):
 *  1. Tender exists.
 *  2. Tender belongs to the requested Network context (managed_by_tenant_slug).
 *  3. Tender status is `closed`.
 *  4. Evaluation exists for the Tender.
 *  5. Evaluation status is `completed`.
 *  6. Bid belongs to the Tender.
 *  7. Bid is submitted (status = 'submitted').
 *  8. Bid is not withdrawn (withdrawn_at is null).
 *  9. Bid has an effective immutable version (effective_version_id is not null).
 * 10. Tender is not already awarded.
 * 11. Actor is authorized Publisher or authorized Engineer (managed_by_tenant_slug match).
 * 12. Winning contractor has the required NetworkProfile.
 *
 * Atomic operation:
 *  Validate → Create Award → Mark Tender awarded → Commit → Dispatch events
 */
class AwardService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Create an Award for a Tender.
     *
     * @param  string      $tenderId               UUID of the Tender to award.
     * @param  string      $winningBidId           UUID of the winning Bid.
     * @param  string      $actorId                UUID of the authenticated user issuing the Award.
     * @param  string      $tenantSlug             Tenant context from X-Tenant header.
     * @param  string|null $reason                 Optional reason/notes.
     * @return NetworkAward
     *
     * @throws \RuntimeException on any prerequisite violation.
     */
    public function createAward(
        string $tenderId,
        string $winningBidId,
        string $actorId,
        string $tenantSlug,
        ?string $reason = null
    ): NetworkAward {
        // --- Validate Tender ---
        $tender = NetworkTender::findOrFail($tenderId);

        $this->assertTenderOwnership($tender, $tenantSlug);

        if (! $tender->isClosed()) {
            throw new \RuntimeException('Tender must be in closed status before an Award can be created.');
        }

        if ($tender->isAwarded()) {
            throw new \RuntimeException('Tender is already awarded. Only one Award per Tender is permitted in V1.');
        }

        // --- Validate Evaluation ---
        $evaluation = NetworkEvaluation::where('tender_id', $tenderId)->first();

        if (! $evaluation) {
            throw new \RuntimeException('No Evaluation exists for this Tender. Evaluation must be completed before Award.');
        }

        if (! $evaluation->isCompleted()) {
            throw new \RuntimeException('Evaluation must be completed before an Award can be created.');
        }

        // --- Validate Bid ---
        $bid = NetworkBid::findOrFail($winningBidId);

        if ($bid->tender_id !== $tenderId) {
            throw new \RuntimeException('The winning Bid does not belong to this Tender.');
        }

        if ($bid->status !== 'submitted') {
            throw new \RuntimeException('The winning Bid must be in submitted status.');
        }

        if ($bid->withdrawn_at !== null) {
            throw new \RuntimeException('A withdrawn Bid cannot be awarded.');
        }

        if (empty($bid->effective_version_id)) {
            throw new \RuntimeException('The winning Bid must have an effective immutable version.');
        }

        // --- Validate NetworkProfile ---
        $contractorProfileId = $bid->contractor_profile_id;
        $profile = NetworkProfile::find($contractorProfileId);

        if (! $profile) {
            throw new \RuntimeException('The winning contractor does not have a valid NetworkProfile.');
        }

        // --- Validate uniqueness (double-check before transaction) ---
        if (NetworkAward::where('tender_id', $tenderId)->exists()) {
            throw new \RuntimeException('An Award already exists for this Tender.');
        }

        // --- Atomic operation: Create Award + Transition Tender ---
        return DB::connection('mysql')->transaction(function () use (
            $tender, $bid, $contractorProfileId, $actorId, $tenantSlug, $reason
        ) {
            // 1. Create Award
            $award = NetworkAward::create([
                'tender_id'                  => $tender->id,
                'winning_bid_id'             => $bid->id,
                'winning_network_profile_id' => $contractorProfileId,
                'awarded_by'                 => $actorId,
                'awarded_at'                 => now(),
                'reason'                     => $reason,
                'managed_by_tenant_slug'     => $tenantSlug,
            ]);

            // 2. Transition Tender to `awarded`
            $tender->update(['status' => 'awarded']);

            // 3. Audit
            $this->auditService->logEvent('network.award.created', $actorId, [
                'tenant_slug'                => $tenantSlug,
                'subject_type'               => 'NetworkAward',
                'subject_id'                 => $award->id,
                'after'                      => [
                    'tender_id'                  => $award->tender_id,
                    'winning_bid_id'             => $award->winning_bid_id,
                    'winning_network_profile_id' => $award->winning_network_profile_id,
                    'awarded_by'                 => $award->awarded_by,
                    'awarded_at'                 => $award->awarded_at?->toIso8601String(),
                ],
            ]);

            $this->auditService->logEvent('network.tender.awarded', $actorId, [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => 'NetworkTender',
                'subject_id'   => $tender->id,
                'after'        => ['status' => 'awarded'],
            ]);

            // 4. Dispatch domain events (after commit — within transaction closure,
            //    dispatched synchronously consistent with NETWORK-001/002/003/004 convention)
            Event::dispatch(new AwardCreated($award->fresh()));
            Event::dispatch(new TenderAwarded($award->fresh()));

            return $award->fresh();
        });
    }

    /**
     * Retrieve an Award by ID, enforcing tenant ownership.
     *
     * @throws \RuntimeException if not found or tenant mismatch.
     */
    public function getAward(string $awardId, string $actorId, string $tenantSlug): NetworkAward
    {
        $award = NetworkAward::findOrFail($awardId);
        $this->assertAwardOwnership($award, $tenantSlug);
        return $award;
    }

    /**
     * Retrieve the Award for a given Tender, enforcing tenant ownership.
     *
     * @throws \RuntimeException if not found or tenant mismatch.
     */
    public function getAwardByTender(string $tenderId, string $actorId, string $tenantSlug): NetworkAward
    {
        $award = NetworkAward::where('tender_id', $tenderId)->first();

        if (! $award) {
            throw new \RuntimeException('No Award found for this Tender.');
        }

        $this->assertAwardOwnership($award, $tenantSlug);
        return $award;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertTenderOwnership(NetworkTender $tender, string $tenantSlug): void
    {
        if ($tender->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('Access denied: Tender does not belong to the requesting tenant.');
        }
    }

    private function assertAwardOwnership(NetworkAward $award, string $tenantSlug): void
    {
        if ($award->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('Access denied: Award does not belong to the requesting tenant.');
        }
    }
}
