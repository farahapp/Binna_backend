<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use App\Network\Events\EvaluationCompleted;
use App\Network\Events\EvaluationStarted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * EvaluationService — Bid Evaluation workflow.
 *
 * NETWORK-004 — Bid Evaluation
 *
 * Lifecycle: not_started → open → completed
 * - completed → open: PROHIBITED
 * - cancellation: PROHIBITED in V1
 */
class EvaluationService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * Start an evaluation for a closed tender.
     *
     * Preconditions:
     *  - Tender must exist and be in `closed` status.
     *  - No existing evaluation for this tender (one per tender).
     */
    public function start(string $tenderId, string $actorId, string $tenantSlug): NetworkEvaluation
    {
        $tender = NetworkTender::findOrFail($tenderId);

        if (! $tender->isClosed()) {
            throw new \RuntimeException('Tender must be closed before an evaluation can be started.');
        }

        if (NetworkEvaluation::where('tender_id', $tenderId)->exists()) {
            throw new \RuntimeException('An evaluation already exists for this tender.');
        }

        return DB::connection('mysql')->transaction(function () use ($tenderId, $actorId, $tenantSlug) {
            $evaluation = NetworkEvaluation::create([
                'tender_id'              => $tenderId,
                'status'                 => 'open',
                'started_by'             => $actorId,
                'evaluation_started_at'  => now(),
                'managed_by_tenant_slug' => $tenantSlug,
            ]);

            $this->auditService->logEvent('network.evaluation.started', $actorId, [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => 'NetworkEvaluation',
                'subject_id'   => $evaluation->id,
                'after'        => ['status' => 'open', 'tender_id' => $evaluation->tender_id],
            ]);

            Event::dispatch(new EvaluationStarted($evaluation));

            return $evaluation->fresh();
        });
    }

    /**
     * Complete an open evaluation.
     *
     * Preconditions:
     *  - Evaluation must exist and be in `open` status.
     *  - tenantSlug must match managed_by_tenant_slug.
     */
    public function complete(string $evaluationId, string $actorId, string $tenantSlug): NetworkEvaluation
    {
        $evaluation = NetworkEvaluation::findOrFail($evaluationId);

        if (! $evaluation->isOpen()) {
            throw new \RuntimeException('Evaluation must be open to be completed.');
        }

        $this->assertEvaluationOwnership($evaluation, $tenantSlug);

        return DB::connection('mysql')->transaction(function () use ($evaluation, $actorId, $tenantSlug) {
            $evaluation->update([
                'status'                    => 'completed',
                'completed_by'              => $actorId,
                'evaluation_completed_at'   => now(),
            ]);

            $this->auditService->logEvent('network.evaluation.completed', $actorId, [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => 'NetworkEvaluation',
                'subject_id'   => $evaluation->id,
                'after'        => ['status' => 'completed'],
            ]);

            Event::dispatch(new EvaluationCompleted($evaluation->fresh()));

            return $evaluation->fresh();
        });
    }

    /**
     * Add a note to an open evaluation.
     *
     * Preconditions:
     *  - Evaluation must exist and be in `open` status.
     *  - tenantSlug must match managed_by_tenant_slug.
     */
    public function addNote(string $evaluationId, array $data, string $actorId, string $tenantSlug): NetworkEvaluationNote
    {
        $evaluation = NetworkEvaluation::findOrFail($evaluationId);

        if (! $evaluation->isOpen()) {
            throw new \RuntimeException('Notes can only be added to an open evaluation.');
        }

        $this->assertEvaluationOwnership($evaluation, $tenantSlug);

        return DB::connection('mysql')->transaction(function () use ($evaluation, $data, $actorId, $tenantSlug) {
            $note = NetworkEvaluationNote::create([
                'evaluation_id'          => $evaluation->id,
                'bid_id'                 => $data['bid_id'] ?? null,
                'author_id'              => $actorId,
                'content'                => $data['content'],
                'managed_by_tenant_slug' => $tenantSlug,
            ]);

            $this->auditService->logEvent('network.evaluation.note.added', $actorId, [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => 'NetworkEvaluationNote',
                'subject_id'   => $note->id,
                'after'        => ['evaluation_id' => $evaluation->id, 'bid_id' => $note->bid_id],
            ]);

            return $note;
        });
    }

    /**
     * Get an evaluation with its notes.
     *
     * Preconditions:
     *  - Evaluation must exist.
     *  - tenantSlug must match managed_by_tenant_slug.
     */
    public function getEvaluation(string $evaluationId, string $actorId, string $tenantSlug): NetworkEvaluation
    {
        $evaluation = NetworkEvaluation::with('notes')->findOrFail($evaluationId);
        $this->assertEvaluationOwnership($evaluation, $tenantSlug);
        return $evaluation;
    }

    /**
     * Get all bids for the tender associated with an evaluation.
     *
     * Preconditions:
     *  - Evaluation must exist and be `open` or `completed`.
     *  - tenantSlug must match managed_by_tenant_slug.
     */
    public function getBidsForEvaluation(string $evaluationId, string $actorId, string $tenantSlug): Collection
    {
        $evaluation = NetworkEvaluation::findOrFail($evaluationId);
        $this->assertEvaluationOwnership($evaluation, $tenantSlug);

        if ($evaluation->isNotStarted()) {
            throw new \RuntimeException('Bids cannot be accessed before the evaluation has started.');
        }

        return NetworkBid::where('tender_id', $evaluation->tender_id)
            ->with('versions')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertEvaluationOwnership(NetworkEvaluation $evaluation, string $tenantSlug): void
    {
        if ($evaluation->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('Unauthorized access to another tenant\'s evaluation.');
        }
    }
}
