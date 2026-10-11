<?php

declare(strict_types=1);

namespace App\Procurement;

use App\Approval\ApprovalService;
use App\Audit\AuditService;
use App\Procurement\Events\PurchaseRequestApproved;
use App\Procurement\Events\PurchaseRequestSubmitted;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * PurchaseRequestService — Application/Service Layer for CAP-061.
 *
 * Architectural constraints (TASK-007B / A1 MOD-08):
 *  - Implements Public Contracts for Purchase Requisition lifecycle.
 *  - Dispatches Domain Events: PurchaseRequestSubmitted, PurchaseRequestApproved.
 *  - Integrates with AuditService per established conventions.
 *  - Integrates with ApprovalService per OD-007 (Shared Approval Service).
 *  - MUST NOT depend on Finance, Accounting, Inventory, or HTTP layer.
 *  - Tenant context resolved by caller — never cross-tenant access.
 *  - Does NOT contain RFQ or PurchaseOrder responsibilities.
 *
 * TASK-007B — Procurement Application/Service Layer (CAP-061)
 */
class PurchaseRequestService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts
    // -------------------------------------------------------------------------

    /**
     * getPurchaseRequest — retrieve a PurchaseRequest by ID.
     *
     * @throws InvalidArgumentException If not found or empty ID.
     */
    public function getPurchaseRequest(string $purchaseRequestId): PurchaseRequest
    {
        if (empty($purchaseRequestId)) {
            throw new InvalidArgumentException('purchaseRequestId must not be empty.');
        }

        $pr = PurchaseRequest::find($purchaseRequestId);

        if ($pr === null) {
            throw new InvalidArgumentException("PurchaseRequest [{$purchaseRequestId}] not found.");
        }

        return $pr;
    }

    /**
     * createPurchaseRequest — create a new PurchaseRequest in draft status.
     *
     * @param array{
     *     tenant_slug: string,
     *     title: string,
     *     description?: string|null,
     *     project_id?: string|null,
     *     requested_by?: string|null,
     *     required_date?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function createPurchaseRequest(array $attributes, string|null $actorId = null): PurchaseRequest
    {
        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($attributes['title'])) {
            throw new InvalidArgumentException('title is required.');
        }

        $pr = PurchaseRequest::create([
            'tenant_slug'   => $attributes['tenant_slug'],
            'title'         => $attributes['title'],
            'description'   => $attributes['description'] ?? null,
            'project_id'    => $attributes['project_id'] ?? null,
            'requested_by'  => $attributes['requested_by'] ?? $actorId,
            'required_date' => $attributes['required_date'] ?? null,
            'status'        => 'draft',
        ]);

        $this->auditService->logEvent(
            'procurement.purchase_request.created',
            $actorId,
            [
                'tenant_slug'  => $attributes['tenant_slug'],
                'subject_type' => PurchaseRequest::class,
                'subject_id'   => $pr->id,
                'after'        => ['status' => 'draft', 'title' => $pr->title],
            ]
        );

        return $pr;
    }

    /**
     * submitPurchaseRequest — transition a PurchaseRequest from draft to submitted.
     *
     * Submits for approval via ApprovalService.
     *
     * @throws InvalidArgumentException If not found or not in draft status.
     */
    public function submitPurchaseRequest(string $purchaseRequestId, string $requestedBy, string $tenantSlug): PurchaseRequest
    {
        if (empty($purchaseRequestId)) {
            throw new InvalidArgumentException('purchaseRequestId must not be empty.');
        }

        $pr = $this->getPurchaseRequest($purchaseRequestId);

        if (! $pr->isDraft()) {
            throw new InvalidArgumentException(
                "PurchaseRequest [{$purchaseRequestId}] must be in draft status to submit. Current: {$pr->status}"
            );
        }

        // Request approval via shared Approval Service.
        $approvalRequest = $this->approvalService->requestApproval(
            approvableType: PurchaseRequest::class,
            approvableId:   $pr->id,
            requestedBy:    $requestedBy,
            tenantSlug:     $tenantSlug,
        );

        $pr->update([
            'status'              => 'submitted',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $this->auditService->logEvent(
            'procurement.purchase_request.submitted',
            $requestedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseRequest::class,
                'subject_id'   => $pr->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'submitted'],
            ]
        );

        Event::dispatch(new PurchaseRequestSubmitted(
            purchaseRequestId: $pr->id,
            tenantSlug:        $tenantSlug,
            occurredAt:        new \DateTimeImmutable(),
        ));

        return $pr->fresh();
    }

    /**
     * approvePurchaseRequest — mark a submitted PurchaseRequest as approved.
     *
     * Grants approval via ApprovalService.
     *
     * @throws InvalidArgumentException If not found or not in submitted status.
     */
    public function approvePurchaseRequest(string $purchaseRequestId, string $grantedBy, string $tenantSlug): PurchaseRequest
    {
        if (empty($purchaseRequestId)) {
            throw new InvalidArgumentException('purchaseRequestId must not be empty.');
        }

        $pr = $this->getPurchaseRequest($purchaseRequestId);

        if (! $pr->isSubmitted()) {
            throw new InvalidArgumentException(
                "PurchaseRequest [{$purchaseRequestId}] must be in submitted status to approve. Current: {$pr->status}"
            );
        }

        if ($pr->approval_request_id) {
            $this->approvalService->approve(
                approvalRequestId: $pr->approval_request_id,
                actorId:           $grantedBy,
            );
        }

        $pr->update(['status' => 'approved']);

        $this->auditService->logEvent(
            'procurement.purchase_request.approved',
            $grantedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseRequest::class,
                'subject_id'   => $pr->id,
                'before'       => ['status' => 'submitted'],
                'after'        => ['status' => 'approved'],
            ]
        );

        Event::dispatch(new PurchaseRequestApproved(
            purchaseRequestId: $pr->id,
            tenantSlug:        $tenantSlug,
            occurredAt:        new \DateTimeImmutable(),
        ));

        return $pr->fresh();
    }

    /**
     * rejectPurchaseRequest — mark a submitted PurchaseRequest as rejected.
     *
     * @throws InvalidArgumentException If not found or not in submitted status.
     */
    public function rejectPurchaseRequest(string $purchaseRequestId, string $rejectedBy, string $tenantSlug, string|null $reason = null): PurchaseRequest
    {
        if (empty($purchaseRequestId)) {
            throw new InvalidArgumentException('purchaseRequestId must not be empty.');
        }

        $pr = $this->getPurchaseRequest($purchaseRequestId);

        if (! $pr->isSubmitted()) {
            throw new InvalidArgumentException(
                "PurchaseRequest [{$purchaseRequestId}] must be in submitted status to reject. Current: {$pr->status}"
            );
        }

        if ($pr->approval_request_id) {
            $this->approvalService->reject(
                approvalRequestId: $pr->approval_request_id,
                actorId:           $rejectedBy,
            );
        }

        $pr->update(['status' => 'rejected']);

        $this->auditService->logEvent(
            'procurement.purchase_request.rejected',
            $rejectedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseRequest::class,
                'subject_id'   => $pr->id,
                'before'       => ['status' => 'submitted'],
                'after'        => ['status' => 'rejected', 'reason' => $reason],
            ]
        );

        return $pr->fresh();
    }

    /**
     * cancelPurchaseRequest — cancel a draft or submitted PurchaseRequest.
     *
     * @throws InvalidArgumentException If not found or in a terminal status.
     */
    public function cancelPurchaseRequest(string $purchaseRequestId, string $actorId, string $tenantSlug): PurchaseRequest
    {
        if (empty($purchaseRequestId)) {
            throw new InvalidArgumentException('purchaseRequestId must not be empty.');
        }

        $pr = $this->getPurchaseRequest($purchaseRequestId);

        if ($pr->isApproved() || $pr->isRejected() || $pr->isCancelled()) {
            throw new InvalidArgumentException(
                "PurchaseRequest [{$purchaseRequestId}] cannot be cancelled from status: {$pr->status}"
            );
        }

        if ($pr->approval_request_id && $pr->isSubmitted()) {
            $this->approvalService->cancelApprovalRequest($pr->approval_request_id);
        }

        $pr->update(['status' => 'cancelled']);

        $this->auditService->logEvent(
            'procurement.purchase_request.cancelled',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseRequest::class,
                'subject_id'   => $pr->id,
                'before'       => ['status' => $pr->getOriginal('status') ?? $pr->status],
                'after'        => ['status' => 'cancelled'],
            ]
        );

        return $pr->fresh();
    }
}
