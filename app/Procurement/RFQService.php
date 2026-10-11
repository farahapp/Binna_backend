<?php

declare(strict_types=1);

namespace App\Procurement;

use App\Audit\AuditService;
use App\Procurement\Events\QuotationSelected;
use App\Procurement\Events\RFQSent;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * RFQService — Application/Service Layer for CAP-062.
 *
 * Architectural constraints (TASK-007B / A1 MOD-08):
 *  - Implements Public Contracts for RFQ lifecycle and Quotation comparison.
 *  - Dispatches Domain Events: RFQSent, QuotationSelected.
 *  - Integrates with AuditService per established conventions.
 *  - MUST NOT depend on Finance, Accounting, Inventory, or HTTP layer.
 *  - Tenant context resolved by caller — never cross-tenant access.
 *  - Does NOT contain PurchaseRequest or PurchaseOrder responsibilities.
 *
 * TASK-007B — Procurement Application/Service Layer (CAP-062)
 */
class RFQService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts
    // -------------------------------------------------------------------------

    /**
     * getRFQ — retrieve an RFQ by ID.
     *
     * @throws InvalidArgumentException If not found or empty ID.
     */
    public function getRFQ(string $rfqId): RFQ
    {
        if (empty($rfqId)) {
            throw new InvalidArgumentException('rfqId must not be empty.');
        }

        $rfq = RFQ::find($rfqId);

        if ($rfq === null) {
            throw new InvalidArgumentException("RFQ [{$rfqId}] not found.");
        }

        return $rfq;
    }

    /**
     * createRFQ — create a new RFQ in draft status.
     *
     * @param array{
     *     tenant_slug: string,
     *     title: string,
     *     purchase_request_id?: string|null,
     *     description?: string|null,
     *     deadline?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function createRFQ(array $attributes, string|null $actorId = null): RFQ
    {
        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($attributes['title'])) {
            throw new InvalidArgumentException('title is required.');
        }

        $rfq = RFQ::create([
            'tenant_slug'          => $attributes['tenant_slug'],
            'title'                => $attributes['title'],
            'purchase_request_id'  => $attributes['purchase_request_id'] ?? null,
            'description'          => $attributes['description'] ?? null,
            'deadline'             => $attributes['deadline'] ?? null,
            'status'               => 'draft',
        ]);

        $this->auditService->logEvent(
            'procurement.rfq.created',
            $actorId,
            [
                'tenant_slug'  => $attributes['tenant_slug'],
                'subject_type' => RFQ::class,
                'subject_id'   => $rfq->id,
                'after'        => ['status' => 'draft', 'title' => $rfq->title],
            ]
        );

        return $rfq;
    }

    /**
     * sendRFQ — transition an RFQ from draft to sent.
     *
     * @throws InvalidArgumentException If not found or not in draft status.
     */
    public function sendRFQ(string $rfqId, string $actorId, string $tenantSlug): RFQ
    {
        if (empty($rfqId)) {
            throw new InvalidArgumentException('rfqId must not be empty.');
        }

        $rfq = $this->getRFQ($rfqId);

        if (! $rfq->isDraft()) {
            throw new InvalidArgumentException(
                "RFQ [{$rfqId}] must be in draft status to send. Current: {$rfq->status}"
            );
        }

        $rfq->update(['status' => 'sent']);

        $this->auditService->logEvent(
            'procurement.rfq.sent',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => RFQ::class,
                'subject_id'   => $rfq->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'sent'],
            ]
        );

        Event::dispatch(new RFQSent(
            rfqId:      $rfq->id,
            tenantSlug: $tenantSlug,
            occurredAt: new \DateTimeImmutable(),
        ));

        return $rfq->fresh();
    }

    /**
     * closeRFQ — transition an RFQ from sent to closed.
     *
     * @throws InvalidArgumentException If not found or not in sent status.
     */
    public function closeRFQ(string $rfqId, string $actorId, string $tenantSlug): RFQ
    {
        if (empty($rfqId)) {
            throw new InvalidArgumentException('rfqId must not be empty.');
        }

        $rfq = $this->getRFQ($rfqId);

        if (! $rfq->isSent()) {
            throw new InvalidArgumentException(
                "RFQ [{$rfqId}] must be in sent status to close. Current: {$rfq->status}"
            );
        }

        $rfq->update(['status' => 'closed']);

        $this->auditService->logEvent(
            'procurement.rfq.closed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => RFQ::class,
                'subject_id'   => $rfq->id,
                'before'       => ['status' => 'sent'],
                'after'        => ['status' => 'closed'],
            ]
        );

        return $rfq->fresh();
    }

    /**
     * submitQuotation — submit a supplier quotation in response to an RFQ.
     *
     * @param array{
     *     tenant_slug: string,
     *     supplier_id: string,
     *     total_amount: float|int,
     *     currency?: string,
     *     notes?: string|null,
     *     valid_until?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If RFQ not found or not in sent status.
     */
    public function submitQuotation(string $rfqId, array $attributes, string|null $actorId = null): SupplierQuotation
    {
        if (empty($rfqId)) {
            throw new InvalidArgumentException('rfqId must not be empty.');
        }
        if (empty($attributes['supplier_id'])) {
            throw new InvalidArgumentException('supplier_id is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }

        $rfq = $this->getRFQ($rfqId);

        if (! $rfq->isSent()) {
            throw new InvalidArgumentException(
                "RFQ [{$rfqId}] must be in sent status to accept quotations. Current: {$rfq->status}"
            );
        }

        $quotation = SupplierQuotation::create([
            'tenant_slug'  => $attributes['tenant_slug'],
            'rfq_id'       => $rfqId,
            'supplier_id'  => $attributes['supplier_id'],
            'total_amount' => $attributes['total_amount'],
            'currency'     => $attributes['currency'] ?? 'SAR',
            'notes'        => $attributes['notes'] ?? null,
            'valid_until'  => $attributes['valid_until'] ?? null,
            'status'       => 'submitted',
        ]);

        $this->auditService->logEvent(
            'procurement.quotation.submitted',
            $actorId,
            [
                'tenant_slug'  => $attributes['tenant_slug'],
                'subject_type' => SupplierQuotation::class,
                'subject_id'   => $quotation->id,
                'after'        => ['rfq_id' => $rfqId, 'supplier_id' => $attributes['supplier_id'], 'total_amount' => $attributes['total_amount']],
            ]
        );

        return $quotation;
    }

    /**
     * selectQuotation — select a quotation as the winner for an RFQ.
     *
     * Marks the selected quotation as 'selected' and rejects all others.
     *
     * @throws InvalidArgumentException If quotation not found or RFQ not closed.
     */
    public function selectQuotation(string $quotationId, string $actorId, string $tenantSlug): SupplierQuotation
    {
        if (empty($quotationId)) {
            throw new InvalidArgumentException('quotationId must not be empty.');
        }

        $quotation = SupplierQuotation::find($quotationId);

        if ($quotation === null) {
            throw new InvalidArgumentException("SupplierQuotation [{$quotationId}] not found.");
        }

        $rfq = $this->getRFQ($quotation->rfq_id);

        if (! $rfq->isClosed()) {
            throw new InvalidArgumentException(
                "RFQ [{$rfq->id}] must be closed before selecting a quotation. Current: {$rfq->status}"
            );
        }

        if (! $quotation->isSubmitted()) {
            throw new InvalidArgumentException(
                "SupplierQuotation [{$quotationId}] must be in submitted status to select. Current: {$quotation->status}"
            );
        }

        // Reject all other submitted quotations for this RFQ.
        SupplierQuotation::where('rfq_id', $rfq->id)
            ->where('id', '!=', $quotationId)
            ->where('status', 'submitted')
            ->update(['status' => 'rejected']);

        $quotation->update(['status' => 'selected']);

        $this->auditService->logEvent(
            'procurement.quotation.selected',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SupplierQuotation::class,
                'subject_id'   => $quotation->id,
                'before'       => ['status' => 'submitted'],
                'after'        => ['status' => 'selected'],
            ]
        );

        Event::dispatch(new QuotationSelected(
            quotationId: $quotation->id,
            rfqId:       $rfq->id,
            tenantSlug:  $tenantSlug,
            occurredAt:  new \DateTimeImmutable(),
        ));

        return $quotation->fresh();
    }

    /**
     * compareQuotations — return all quotations for an RFQ sorted by total_amount ascending.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SupplierQuotation>
     */
    public function compareQuotations(string $rfqId): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($rfqId)) {
            throw new InvalidArgumentException('rfqId must not be empty.');
        }

        // Verify RFQ exists.
        $this->getRFQ($rfqId);

        return SupplierQuotation::where('rfq_id', $rfqId)
            ->orderBy('total_amount', 'asc')
            ->get();
    }
}
