<?php

declare(strict_types=1);

namespace App\Approval;

use App\Approval\Events\ApprovalGranted;
use App\Approval\Events\ApprovalRejected;
use Illuminate\Support\Facades\Event;
use LogicException;

/**
 * ApprovalService — Shared Approval Service (OD-007 Option 2).
 *
 * SC-APPROVAL-1: Approver identity rule is UNRESOLVED (SPECIFICATION CLARIFICATION).
 *   requestApproval() creates the ApprovalRequest record but does NOT determine
 *   the authorized approver. That logic is deferred pending SC-APPROVAL-1 resolution.
 *
 * SC-APPROVAL-2: DB write failure semantics are UNRESOLVED (SPECIFICATION CLARIFICATION).
 *   requestApproval() uses a standard DB transaction. Retry/compensation behavior
 *   beyond normal transaction rollback is NOT implemented pending SC-APPROVAL-2 resolution.
 *
 * F-13: UUID convention follows the established project pattern (HasUuids).
 *   TODEC-003 specifies UUID v4; HasUuids generates UUID v7. This task does not
 *   resolve F-13 — it follows the existing project convention.
 */
class ApprovalService
{
    /**
     * Create a new ApprovalRequest in PENDING state.
     *
     * SC-APPROVAL-1: Approver identity rule is NOT implemented here.
     * SC-APPROVAL-2: Only standard DB transaction semantics apply.
     *
     * @param string      $approvableType  The type of the entity requiring approval (e.g. 'App\Procurement\PurchaseOrder')
     * @param string      $approvableId    The UUID of the entity requiring approval
     * @param string      $requestedBy     The UUID of the IAM User requesting approval
     * @param string      $tenantSlug      The tenant slug (from TenantContext)
     * @param string|null $notes           Optional notes
     */
    public function requestApproval(
        string $approvableType,
        string $approvableId,
        string $requestedBy,
        string $tenantSlug,
        string|null $notes = null,
    ): ApprovalRequest {
        if (empty($approvableType) || empty($approvableId) || empty($requestedBy) || empty($tenantSlug)) {
            throw new \InvalidArgumentException('approvableType, approvableId, requestedBy, and tenantSlug are required.');
        }

        return ApprovalRequest::create([
            'approvable_type' => $approvableType,
            'approvable_id'   => $approvableId,
            'requested_by'    => $requestedBy,
            'status'          => ApprovalStatus::PENDING,
            'tenant_slug'     => $tenantSlug,
            'notes'           => $notes,
        ]);
    }

    /**
     * Retrieve the current status of an ApprovalRequest.
     */
    public function getApprovalStatus(string $approvalRequestId): ApprovalRequest
    {
        $request = $this->findRequest($approvalRequestId);

        if ($request === null) {
            throw new \InvalidArgumentException("ApprovalRequest [{$approvalRequestId}] not found.");
        }

        return $request;
    }

    /**
     * Cancel a PENDING ApprovalRequest.
     *
     * Delegates transition validation to ApprovalRequest::transitionTo().
     * InvalidStateTransitionException is thrown for terminal or self-transitions.
     *
     * SC-APPROVAL-2: Only standard DB save semantics apply.
     */
    public function cancelApprovalRequest(string $approvalRequestId): ApprovalRequest
    {
        $request = $this->findRequest($approvalRequestId);

        if ($request === null) {
            throw new \InvalidArgumentException("ApprovalRequest [{$approvalRequestId}] not found.");
        }

        $request->transitionTo(ApprovalStatus::CANCELLED);
        $this->saveRequest($request);

        return $request;
    }

    /**
     * Approve a PENDING ApprovalRequest.
     *
     * Delegates transition validation to ApprovalRequest::transitionTo().
     * InvalidStateTransitionException is thrown for terminal or self-transitions.
     *
     * SC-APPROVAL-1: The authorization check (who is allowed to approve) is NOT
     * implemented here. That logic is deferred pending SC-APPROVAL-1 resolution.
     * SC-APPROVAL-2: Only standard DB save semantics apply.
     *
     * @param string      $approvalRequestId
     * @param string|null $actorId  The UUID of the IAM User performing the approval (SC-APPROVAL-1 pending)
     */
    public function approve(string $approvalRequestId, string|null $actorId = null): ApprovalRequest
    {
        $request = $this->findRequest($approvalRequestId);

        if ($request === null) {
            throw new \InvalidArgumentException("ApprovalRequest [{$approvalRequestId}] not found.");
        }

        $request->transitionTo(ApprovalStatus::APPROVED);
        $this->saveRequest($request);

        Event::dispatch(new ApprovalGranted($request, $actorId));

        return $request;
    }

    /**
     * Reject a PENDING ApprovalRequest.
     *
     * Delegates transition validation to ApprovalRequest::transitionTo().
     * InvalidStateTransitionException is thrown for terminal or self-transitions.
     *
     * SC-APPROVAL-1: The authorization check (who is allowed to reject) is NOT
     * implemented here. That logic is deferred pending SC-APPROVAL-1 resolution.
     * SC-APPROVAL-2: Only standard DB save semantics apply.
     *
     * @param string      $approvalRequestId
     * @param string|null $actorId  The UUID of the IAM User performing the rejection (SC-APPROVAL-1 pending)
     */
    public function reject(string $approvalRequestId, string|null $actorId = null): ApprovalRequest
    {
        $request = $this->findRequest($approvalRequestId);

        if ($request === null) {
            throw new \InvalidArgumentException("ApprovalRequest [{$approvalRequestId}] not found.");
        }

        $request->transitionTo(ApprovalStatus::REJECTED);
        $this->saveRequest($request);

        Event::dispatch(new ApprovalRejected($request, $actorId));

        return $request;
    }

    /**
     * Retrieve an ApprovalRequest by ID.
     *
     * Extracted as a protected method to allow test subclasses to inject
     * in-memory instances without hitting the database.
     */
    protected function findRequest(string $id): ?ApprovalRequest
    {
        return ApprovalRequest::find($id);
    }

    /**
     * Persist an ApprovalRequest.
     *
     * Extracted as a protected method to allow test subclasses to inject
     * in-memory persistence without hitting the database.
     */
    protected function saveRequest(ApprovalRequest $request): void
    {
        $request->save();
    }
}
