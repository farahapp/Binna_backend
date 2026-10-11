<?php

declare(strict_types=1);

namespace App\Approval;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ApprovalRequest — Per-tenant approval record.
 *
 * Architectural notes:
 *
 * SC-APPROVAL-1 (UNRESOLVED — SPECIFICATION CLARIFICATION):
 *   Approver identity rule is NOT implemented. The model stores requested_by
 *   (the requester) but does NOT store or enforce who is authorized to approve.
 *   That logic is deferred pending SC-APPROVAL-1 resolution.
 *
 * SC-APPROVAL-2 (UNRESOLVED — SPECIFICATION CLARIFICATION):
 *   DB write failure semantics beyond standard transaction rollback are NOT
 *   implemented. Retry/compensation behavior is deferred pending SC-APPROVAL-2
 *   resolution.
 *
 * F-13 (UNRESOLVED — UUID CONVENTION):
 *   TODEC-003 specifies UUID v4; HasUuids generates UUID v7. This model follows
 *   the established project convention (HasUuids) without resolving F-13.
 *
 * Lives in the per-tenant schema (connection = 'tenant'), following the same
 * pattern as AuditEntry and IAM models.
 */
class ApprovalRequest extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — follows the established project convention (OD-001).
     */
    protected $connection = 'tenant';

    protected $table = 'approval_requests';

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'requested_by',
        'status',
        'tenant_slug',
        'notes',
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
    ];

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true if the request is in a terminal state (APPROVED, REJECTED, or CANCELLED).
     * A terminal request cannot transition to any other state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            ApprovalStatus::APPROVED,
            ApprovalStatus::REJECTED,
            ApprovalStatus::CANCELLED,
        ], true);
    }

    /**
     * Returns true if the request is still in PENDING state.
     */
    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::PENDING;
    }

    // -------------------------------------------------------------------------
    // State machine — TASK-002A-2
    // -------------------------------------------------------------------------

    /**
     * Valid transitions defined by the architecture:
     *   PENDING → APPROVED
     *   PENDING → REJECTED
     *   PENDING → CANCELLED
     *
     * All terminal states (APPROVED, REJECTED, CANCELLED) are final.
     * PENDING → PENDING is rejected (no self-transition).
     *
     * SC-APPROVAL-1 / SC-APPROVAL-2 / F-13 remain UNRESOLVED — not modified here.
     *
     * @throws \App\Approval\InvalidStateTransitionException
     */
    public function transitionTo(ApprovalStatus $newStatus): void
    {
        $current = $this->status;

        if ($current === $newStatus) {
            throw new InvalidStateTransitionException(
                "Cannot transition from {$current->value} to {$newStatus->value}: self-transition is not allowed."
            );
        }

        if ($this->isTerminal()) {
            throw new InvalidStateTransitionException(
                "Cannot transition from terminal state {$current->value} to {$newStatus->value}."
            );
        }

        // At this point current must be PENDING (the only non-terminal state).
        // All three target states (APPROVED, REJECTED, CANCELLED) are valid.
        $this->status = $newStatus;
    }
}
