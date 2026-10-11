<?php

declare(strict_types=1);

namespace App\Procurement;

use App\Approval\ApprovalService;
use App\Audit\AuditService;
use App\Procurement\Events\PurchaseOrderIssued;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * PurchaseOrderService — Application/Service Layer for CAP-063.
 *
 * Architectural constraints (TASK-007B / A1 MOD-08):
 *  - Implements Public Contracts for Purchase Order V1 lifecycle.
 *  - Dispatches Domain Events: PurchaseOrderIssued.
 *  - Integrates with AuditService per established conventions.
 *  - Integrates with ApprovalService per OD-007 (Shared Approval Service).
 *  - MUST NOT depend on Finance, Accounting, Inventory, or HTTP layer.
 *  - Tenant context resolved by caller — never cross-tenant access.
 *  - Does NOT contain PurchaseRequest or RFQ responsibilities.
 *
 * TASK-007B — Procurement Application/Service Layer (CAP-063)
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts
    // -------------------------------------------------------------------------

    /**
     * getPurchaseOrder — retrieve a PurchaseOrder by ID.
     *
     * @throws InvalidArgumentException If not found or empty ID.
     */
    public function getPurchaseOrder(string $purchaseOrderId): PurchaseOrder
    {
        if (empty($purchaseOrderId)) {
            throw new InvalidArgumentException('purchaseOrderId must not be empty.');
        }

        $po = PurchaseOrder::find($purchaseOrderId);

        if ($po === null) {
            throw new InvalidArgumentException("PurchaseOrder [{$purchaseOrderId}] not found.");
        }

        return $po;
    }

    /**
     * createPurchaseOrder — create a new PurchaseOrder in draft status.
     *
     * @param array{
     *     tenant_slug: string,
     *     supplier_id: string,
     *     purchase_request_id?: string|null,
     *     po_number?: string|null,
     *     total_amount?: float|int,
     *     currency?: string,
     *     issued_date?: string|null,
     *     expected_delivery_date?: string|null,
     *     notes?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function createPurchaseOrder(array $attributes, string|null $actorId = null): PurchaseOrder
    {
        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($attributes['supplier_id'])) {
            throw new InvalidArgumentException('supplier_id is required.');
        }

        $po = PurchaseOrder::create([
            'tenant_slug'            => $attributes['tenant_slug'],
            'supplier_id'            => $attributes['supplier_id'],
            'purchase_request_id'    => $attributes['purchase_request_id'] ?? null,
            'po_number'              => $attributes['po_number'] ?? null,
            'total_amount'           => $attributes['total_amount'] ?? 0,
            'currency'               => $attributes['currency'] ?? 'SAR',
            'issued_date'            => $attributes['issued_date'] ?? null,
            'expected_delivery_date' => $attributes['expected_delivery_date'] ?? null,
            'notes'                  => $attributes['notes'] ?? null,
            'status'                 => 'draft',
        ]);

        $this->auditService->logEvent(
            'procurement.purchase_order.created',
            $actorId,
            [
                'tenant_slug'  => $attributes['tenant_slug'],
                'subject_type' => PurchaseOrder::class,
                'subject_id'   => $po->id,
                'after'        => ['status' => 'draft', 'supplier_id' => $po->supplier_id],
            ]
        );

        return $po;
    }

    /**
     * addItem — add a line item to a draft PurchaseOrder.
     *
     * @param array{
     *     description: string,
     *     quantity: float|int,
     *     unit?: string|null,
     *     unit_price: float|int,
     *     total_price: float|int,
     * } $itemAttributes
     * @throws InvalidArgumentException If PO not found or not in draft status.
     */
    public function addItem(string $purchaseOrderId, array $itemAttributes): POItem
    {
        if (empty($purchaseOrderId)) {
            throw new InvalidArgumentException('purchaseOrderId must not be empty.');
        }

        $po = $this->getPurchaseOrder($purchaseOrderId);

        if (! $po->isDraft()) {
            throw new InvalidArgumentException(
                "PurchaseOrder [{$purchaseOrderId}] must be in draft status to add items. Current: {$po->status}"
            );
        }

        if (empty($itemAttributes['description'])) {
            throw new InvalidArgumentException('item description is required.');
        }

        return POItem::create([
            'purchase_order_id' => $purchaseOrderId,
            'description'       => $itemAttributes['description'],
            'quantity'          => $itemAttributes['quantity'] ?? 1,
            'unit'              => $itemAttributes['unit'] ?? null,
            'unit_price'        => $itemAttributes['unit_price'] ?? 0,
            'total_price'       => $itemAttributes['total_price'] ?? 0,
        ]);
    }

    /**
     * submitPurchaseOrder — transition a PurchaseOrder from draft to submitted.
     *
     * Submits for approval via ApprovalService.
     *
     * @throws InvalidArgumentException If not found or not in draft status.
     */
    public function submitPurchaseOrder(string $purchaseOrderId, string $requestedBy, string $tenantSlug): PurchaseOrder
    {
        if (empty($purchaseOrderId)) {
            throw new InvalidArgumentException('purchaseOrderId must not be empty.');
        }

        $po = $this->getPurchaseOrder($purchaseOrderId);

        if (! $po->isDraft()) {
            throw new InvalidArgumentException(
                "PurchaseOrder [{$purchaseOrderId}] must be in draft status to submit. Current: {$po->status}"
            );
        }

        $approvalRequest = $this->approvalService->requestApproval(
            approvableType: PurchaseOrder::class,
            approvableId:   $po->id,
            requestedBy:    $requestedBy,
            tenantSlug:     $tenantSlug,
        );

        $po->update([
            'status'              => 'submitted',
            'approval_request_id' => $approvalRequest->id,
        ]);

        $this->auditService->logEvent(
            'procurement.purchase_order.submitted',
            $requestedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseOrder::class,
                'subject_id'   => $po->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'submitted'],
            ]
        );

        return $po->fresh();
    }

    /**
     * approvePurchaseOrder — mark a submitted PurchaseOrder as approved.
     *
     * @throws InvalidArgumentException If not found or not in submitted status.
     */
    public function approvePurchaseOrder(string $purchaseOrderId, string $grantedBy, string $tenantSlug): PurchaseOrder
    {
        if (empty($purchaseOrderId)) {
            throw new InvalidArgumentException('purchaseOrderId must not be empty.');
        }

        $po = $this->getPurchaseOrder($purchaseOrderId);

        if (! $po->isSubmitted()) {
            throw new InvalidArgumentException(
                "PurchaseOrder [{$purchaseOrderId}] must be in submitted status to approve. Current: {$po->status}"
            );
        }

        if ($po->approval_request_id) {
            $this->approvalService->approve(
                approvalRequestId: $po->approval_request_id,
                actorId:           $grantedBy,
            );
        }

        $po->update(['status' => 'approved']);

        $this->auditService->logEvent(
            'procurement.purchase_order.approved',
            $grantedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseOrder::class,
                'subject_id'   => $po->id,
                'before'       => ['status' => 'submitted'],
                'after'        => ['status' => 'approved'],
            ]
        );

        return $po->fresh();
    }

    /**
     * issuePurchaseOrder — transition an approved PurchaseOrder to issued.
     *
     * @throws InvalidArgumentException If not found or not in approved status.
     */
    public function issuePurchaseOrder(string $purchaseOrderId, string $actorId, string $tenantSlug): PurchaseOrder
    {
        if (empty($purchaseOrderId)) {
            throw new InvalidArgumentException('purchaseOrderId must not be empty.');
        }

        $po = $this->getPurchaseOrder($purchaseOrderId);

        if (! $po->isApproved()) {
            throw new InvalidArgumentException(
                "PurchaseOrder [{$purchaseOrderId}] must be in approved status to issue. Current: {$po->status}"
            );
        }

        $po->update([
            'status'      => 'issued',
            'issued_date' => now()->toDateString(),
        ]);

        $this->auditService->logEvent(
            'procurement.purchase_order.issued',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseOrder::class,
                'subject_id'   => $po->id,
                'before'       => ['status' => 'approved'],
                'after'        => ['status' => 'issued'],
            ]
        );

        Event::dispatch(new PurchaseOrderIssued(
            purchaseOrderId: $po->id,
            tenantSlug:      $tenantSlug,
            occurredAt:      new \DateTimeImmutable(),
        ));

        return $po->fresh();
    }

    /**
     * cancelPurchaseOrder — cancel a draft or submitted PurchaseOrder.
     *
     * @throws InvalidArgumentException If not found or in a terminal/issued status.
     */
    public function cancelPurchaseOrder(string $purchaseOrderId, string $actorId, string $tenantSlug): PurchaseOrder
    {
        if (empty($purchaseOrderId)) {
            throw new InvalidArgumentException('purchaseOrderId must not be empty.');
        }

        $po = $this->getPurchaseOrder($purchaseOrderId);

        if ($po->isIssued() || $po->isCompleted() || $po->isCancelled()) {
            throw new InvalidArgumentException(
                "PurchaseOrder [{$purchaseOrderId}] cannot be cancelled from status: {$po->status}"
            );
        }

        if ($po->approval_request_id && $po->isSubmitted()) {
            $this->approvalService->cancelApprovalRequest($po->approval_request_id);
        }

        $po->update(['status' => 'cancelled']);

        $this->auditService->logEvent(
            'procurement.purchase_order.cancelled',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PurchaseOrder::class,
                'subject_id'   => $po->id,
                'before'       => ['status' => $po->getOriginal('status') ?? $po->status],
                'after'        => ['status' => 'cancelled'],
            ]
        );

        return $po->fresh();
    }

    /**
     * ListPurchaseOrders — paginated list of PurchaseOrders in the active tenant schema.
     *
     * Tenant isolation: PurchaseOrder uses connection='tenant' (OD-001).
     * WEB-001 PHASE 4 — Purchase Orders List API
     */
    public function listPurchaseOrders(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return PurchaseOrder::orderByDesc('created_at')->paginate($perPage);
    }
}
