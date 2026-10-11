<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use App\Network\Events\BidRevised;
use App\Network\Events\BidRevisionRejected;
use App\Network\Events\BidSubmitted;
use App\Network\Events\BidWithdrawn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class BidService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function submit(array $data, string $actorId, string $tenantSlug): NetworkBid
    {
        $tender = NetworkTender::findOrFail($data['tender_id']);
        $profile = NetworkProfile::findOrFail($data['contractor_profile_id']);
        $this->assertProfileOwnership($profile, $tenantSlug);
        $this->assertEligibleProfile($profile, $tender);
        $this->assertSubmissionWindow($tender);

        if (NetworkBid::where('tender_id', $tender->id)->where('contractor_profile_id', $profile->id)->exists()) {
            throw new \RuntimeException('A Bid already exists; submit a Revision instead.');
        }

        return DB::connection('mysql')->transaction(function () use ($data, $actorId, $tenantSlug, $tender, $profile) {
            $now = now();
            $bid = NetworkBid::create([
                'tender_id' => $tender->id,
                'contractor_profile_id' => $profile->id,
                'status' => 'submitted',
                'latest_revision_number' => 1,
                'submitted_at' => $now,
                'managed_by_tenant_slug' => $tenantSlug,
            ]);
            $version = NetworkBidVersion::create([
                'bid_id' => $bid->id,
                'revision_number' => 1,
                'content' => $data['content'],
                'actor_id' => $actorId,
                'reason' => $data['reason'] ?? null,
                'status' => 'effective',
                'submitted_at' => $now,
            ]);
            $bid->effective_version_id = $version->id;
            $bid->save();
            $this->audit('network.bid.submitted', $bid, $actorId, $tenantSlug, ['revision_number' => 1]);
            Event::dispatch(new BidSubmitted($bid, $version));
            return $bid->fresh()->load('versions');
        });
    }

    public function get(string $id, string $tenantSlug): NetworkBid
    {
        $bid = NetworkBid::with('versions')->findOrFail($id);
        $this->assertBidOwnership($bid, $tenantSlug);
        return $bid;
    }

    public function revise(string $id, array $data, string $actorId, string $tenantSlug): NetworkBid
    {
        $bid = NetworkBid::with('versions')->findOrFail($id);
        try {
            $this->assertBidOwnership($bid, $tenantSlug);
            $tender = NetworkTender::findOrFail($bid->tender_id);
            $this->assertSubmissionWindow($tender);
        } catch (\Throwable $e) {
            $this->auditRejectedRevision($bid, $actorId, $tenantSlug, $e->getMessage());
            Event::dispatch(new BidRevisionRejected($bid->id, $actorId, $e->getMessage()));
            throw $e;
        }

        return DB::connection('mysql')->transaction(function () use ($bid, $data, $actorId, $tenantSlug) {
            $now = now();
            NetworkBidVersion::where('bid_id', $bid->id)->where('status', 'effective')
                ->update(['status' => $bid->status === 'withdrawn' ? 'withdrawn' : 'superseded']);
            $revision = $bid->latest_revision_number + 1;
            $version = NetworkBidVersion::create([
                'bid_id' => $bid->id,
                'revision_number' => $revision,
                'content' => $data['content'],
                'actor_id' => $actorId,
                'reason' => $data['reason'] ?? null,
                'status' => 'effective',
                'submitted_at' => $now,
            ]);
            $bid->update([
                'effective_version_id' => $version->id,
                'latest_revision_number' => $revision,
                'status' => 'revised',
                'withdrawn_at' => null,
            ]);
            $this->audit('network.bid.revised', $bid, $actorId, $tenantSlug, ['revision_number' => $revision]);
            Event::dispatch(new BidRevised($bid, $version));
            return $bid->fresh()->load('versions');
        });
    }

    public function withdraw(string $id, ?string $reason, string $actorId, string $tenantSlug): NetworkBid
    {
        $bid = NetworkBid::with('versions')->findOrFail($id);
        $this->assertBidOwnership($bid, $tenantSlug);
        $this->assertSubmissionWindow(NetworkTender::findOrFail($bid->tender_id));
        if ($bid->status === 'withdrawn') {
            throw new \RuntimeException('Bid is already withdrawn.');
        }

        return DB::connection('mysql')->transaction(function () use ($bid, $reason, $actorId, $tenantSlug) {
            $now = now();
            NetworkBidVersion::where('id', $bid->effective_version_id)->update([
                'status' => 'withdrawn', 'withdrawn_at' => $now,
            ]);
            $bid->update(['status' => 'withdrawn', 'withdrawn_at' => $now]);
            $this->audit('network.bid.withdrawn', $bid, $actorId, $tenantSlug, ['reason' => $reason]);
            Event::dispatch(new BidWithdrawn($bid));
            return $bid->fresh()->load('versions');
        });
    }

    private function assertSubmissionWindow(NetworkTender $tender): void
    {
        if (! $tender->isPublished()) {
            throw new \RuntimeException('Tender is not open for Bid submission.');
        }
        if ($tender->deadline === null || ! now()->lt($tender->deadline)) {
            throw new \RuntimeException('Tender deadline has been reached.');
        }
        // NETWORK-003 has no Evaluation workflow; any future evaluation state must close this gate.
    }

    private function assertProfileOwnership(NetworkProfile $profile, string $tenantSlug): void
    {
        if ($profile->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('Contractor NetworkProfile is not managed by this tenant.');
        }
    }

    private function assertEligibleProfile(NetworkProfile $profile, NetworkTender $tender): void
    {
        if ($profile->status !== 'active') {
            throw new \RuntimeException('Contractor NetworkProfile is not active.');
        }
        $allowed = $tender->participant_types;
        if (is_array($allowed) && $allowed !== [] && ! in_array($profile->profile_type, $allowed, true)) {
            throw new \RuntimeException('Contractor NetworkProfile is not eligible for this Tender.');
        }
        if (! $tender->isPublic() && $tender->managed_by_tenant_slug !== $profile->managed_by_tenant_slug) {
            throw new \RuntimeException('Contractor is not authorized for this Tender visibility.');
        }
    }

    private function assertBidOwnership(NetworkBid $bid, string $tenantSlug): void
    {
        if ($bid->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('Unauthorized access to another contractor Bid.');
        }
    }

    private function audit(string $event, NetworkBid $bid, string $actorId, string $tenantSlug, array $after): void
    {
        $this->auditService->logEvent($event, $actorId, [
            'tenant_slug' => $tenantSlug,
            'subject_type' => 'NetworkBid',
            'subject_id' => $bid->id,
            'after' => $after,
        ]);
    }

    private function auditRejectedRevision(NetworkBid $bid, string $actorId, string $tenantSlug, string $reason): void
    {
        $this->audit('network.bid.revision_rejected', $bid, $actorId, $tenantSlug, ['reason' => $reason]);
    }
}
