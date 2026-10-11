<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Approval\ApprovalService;
use App\Audit\AuditService;
use App\Commercial\Events\ContractSigned;
use App\Commercial\Events\ContractVariationApproved;
use App\Commercial\Events\QuotationAccepted;
use App\Commercial\Events\TenderAwarded;
use App\CRM\CRMService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * CommercialService — Commercial Application/Service Layer.
 *
 * Architectural constraints (A1 MOD-05 / A4 §4.2 / OD-001):
 *  - Commercial is the System of Record for contractual obligations.
 *  - Operates within the active per-tenant schema (OD-001).
 *  - Does NOT accept tenantSlug as a bypass parameter — tenant context
 *    is resolved by the existing model connection mechanism (connection = 'tenant').
 *  - B2 adds: CRMService (getClient) + AuditService (logEvent).
 *
 * Public Contracts (A4 §4.2 / TASK-004B1):
 *  - getContract(contractId)      → Contract
 *  - getContractValue(contractId) → array{value, approved_variations_value, revised_contract_value}
 *  - getBOQ(boqId)                → BOQ
 *
 * Creation Operations (TASK-004B2-UNIT1):
 *  - createTender(attributes, actorId)     → Tender
 *  - createBOQ(attributes, actorId)        → BOQ
 *  - createQuotation(attributes, actorId)  → Quotation
 *  - createContract(attributes, actorId)   → Contract
 *  - createVariation(attributes, actorId)  → ContractVariation
 *
 * Forbidden dependencies (A1 MOD-05):
 *  - MUST NOT depend on Finance, Accounting, Invoicing, Receivables,
 *    Procurement, HR, Equipment, Projects, or ApprovalService.
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 * TASK-004B2-UNIT1 — Creation Operations
 */
class CommercialService
{
    public function __construct(
        private readonly CRMService $crmService,
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}
    // =========================================================================
    // PUBLIC CONTRACTS (A4 §4.2 / TASK-004B1)
    // =========================================================================

    /**
     * GetContract — retrieve a Contract by ID.
     *
     * QUERY contract (A4 §4.2):
     *  - Input:  contractId (UUID string)
     *  - Output: Contract model
     *  - Failure: throws InvalidArgumentException if not found or empty ID.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @throws \InvalidArgumentException If contractId is empty or the contract does not exist.
     */
    public function getContract(string $contractId): Contract
    {
        if (empty($contractId)) {
            throw new \InvalidArgumentException('contractId must not be empty.');
        }

        $contract = Contract::find($contractId);

        if ($contract === null) {
            throw new \InvalidArgumentException("Contract [{$contractId}] not found.");
        }

        return $contract;
    }

    /**
     * GetContractValue — retrieve the financial value breakdown of a Contract.
     *
     * QUERY contract (A4 §4.2):
     *  - Input:  contractId (UUID string)
     *  - Output: array{
     *        value:                     string|null,
     *        approved_variations_value: string|null,
     *        revised_contract_value:    string|null,
     *    }
     *  - Failure: throws InvalidArgumentException (via getContract()) if not found.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *  - Values are read directly from Contract fields — no calculation performed.
     *
     * @return array{value: string|null, approved_variations_value: string|null, revised_contract_value: string|null}
     * @throws \InvalidArgumentException If contractId is empty or the contract does not exist.
     */
    public function getContractValue(string $contractId): array
    {
        $contract = $this->getContract($contractId);

        return [
            'value'                     => $contract->contract_value,
            'approved_variations_value' => $contract->approved_variations_value,
            'revised_contract_value'    => $contract->revised_contract_value,
        ];
    }

    /**
     * GetBOQ — retrieve a BOQ by ID.
     *
     * QUERY contract (A4 §4.2):
     *  - Input:  boqId (UUID string)
     *  - Output: BOQ model
     *  - Failure: throws InvalidArgumentException if not found or empty ID.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @throws \InvalidArgumentException If boqId is empty or the BOQ does not exist.
     */
    public function getBOQ(string $boqId): BOQ
    {
        if (empty($boqId)) {
            throw new \InvalidArgumentException('boqId must not be empty.');
        }

        $boq = BOQ::find($boqId);

        if ($boq === null) {
            throw new \InvalidArgumentException("BOQ [{$boqId}] not found.");
        }

        return $boq;
    }

    // =========================================================================
    // CREATION OPERATIONS (TASK-004B2-UNIT1)
    // =========================================================================

    /**
     * CreateTender — create and persist a new Tender in DRAFT status.
     *
     * CRM integration: getClient() is the authorized B2 contract (MEP TASK-004B2).
     * Client must be active. Audit: commercial.tender.created.
     * No lifecycle transitions, no approval, no domain event dispatch in UNIT1.
     *
     * @param array{
     *     title:                string,
     *     tenant_slug:          string,
     *     client_id:            string,
     *     reference_number?:    string|null,
     *     description?:         string|null,
     *     submission_deadline?: string|null,
     *     notes?:               string|null,
     * } $attributes
     * @throws \InvalidArgumentException On invalid input or inactive client.
     */
    public function createTender(array $attributes, string|null $actorId = null): Tender
    {
        $this->validateTenderAttributes($attributes);

        // CRM integration: authorized getClient() contract (TASK-004B2 / MEP)
        $client = $this->crmService->getClient($attributes['client_id']);
        if ($client->status !== 'active') {
            throw new \InvalidArgumentException(
                "Client [{$attributes['client_id']}] is not active (status: {$client->status})."
            );
        }

        $tender = Tender::create([
            'reference_number'    => $attributes['reference_number'] ?? null,
            'title'               => $attributes['title'],
            'description'         => $attributes['description'] ?? null,
            'client_id'           => $attributes['client_id'],
            'status'              => 'DRAFT',
            'submission_deadline' => $attributes['submission_deadline'] ?? null,
            'notes'               => $attributes['notes'] ?? null,
            'tenant_slug'         => $attributes['tenant_slug'],
        ]);

        $this->auditService->logEvent(
            'commercial.tender.created',
            $actorId,
            [
                'tenant_slug'  => $tender->tenant_slug,
                'subject_type' => Tender::class,
                'subject_id'   => $tender->id,
                'after'        => ['title' => $tender->title, 'status' => $tender->status],
            ]
        );

        return $tender;
    }

    /**
     * CreateBOQ — create and persist a new BOQ in DRAFT status.
     *
     * BOQ is the authorized aggregate boundary: sections and items are persisted
     * within this operation if provided (SCOPE RESOLUTION REPORT / UNIT1 contract).
     * Transaction wraps BOQ + sections + items for atomicity.
     * Audit: commercial.boq.created.
     * No lifecycle transitions in UNIT1.
     *
     * @param array{
     *     type:          string,
     *     title:         string,
     *     tenant_slug:   string,
     *     tender_id?:    string|null,
     *     contract_id?:  string|null,
     *     variation_id?: string|null,
     *     notes?:        string|null,
     *     sections?:     array<int, array{
     *         title:       string,
     *         description?: string|null,
     *         sort_order?:  int,
     *         items?:       array<int, array{
     *             description:   string,
     *             unit?:         string|null,
     *             quantity?:     string|null,
     *             unit_rate?:    string|null,
     *             amount?:       string|null,
     *             wbs_reference?: string|null,
     *             sort_order?:   int,
     *             notes?:        string|null,
     *         }>,
     *     }>,
     * } $attributes
     * @throws \InvalidArgumentException On invalid input.
     */
    public function createBOQ(array $attributes, string|null $actorId = null): BOQ
    {
        $this->validateBOQAttributes($attributes);

        return DB::transaction(function () use ($attributes, $actorId) {
            $boq = BOQ::create([
                'type'         => $attributes['type'],
                'title'        => $attributes['title'],
                'tender_id'    => $attributes['tender_id'] ?? null,
                'contract_id'  => $attributes['contract_id'] ?? null,
                'variation_id' => $attributes['variation_id'] ?? null,
                'status'       => 'DRAFT',
                'notes'        => $attributes['notes'] ?? null,
                'tenant_slug'  => $attributes['tenant_slug'],
            ]);

            // Persist BOQ aggregate (sections + items) if provided
            foreach ($attributes['sections'] ?? [] as $sectionData) {
                $section = $boq->sections()->create([
                    'title'       => $sectionData['title'],
                    'description' => $sectionData['description'] ?? null,
                    'sort_order'  => $sectionData['sort_order'] ?? 0,
                    'tenant_slug' => $attributes['tenant_slug'],
                ]);

                foreach ($sectionData['items'] ?? [] as $itemData) {
                    $section->items()->create([
                        'description'   => $itemData['description'],
                        'unit'          => $itemData['unit'] ?? null,
                        'quantity'      => $itemData['quantity'] ?? null,
                        'unit_rate'     => $itemData['unit_rate'] ?? null,
                        'amount'        => $itemData['amount'] ?? '0',
                        'wbs_reference' => $itemData['wbs_reference'] ?? null,
                        'sort_order'    => $itemData['sort_order'] ?? 0,
                        'notes'         => $itemData['notes'] ?? null,
                        'tenant_slug'   => $attributes['tenant_slug'],
                    ]);
                }
            }

            $this->auditService->logEvent(
                'commercial.boq.created',
                $actorId,
                [
                    'tenant_slug'  => $boq->tenant_slug,
                    'subject_type' => BOQ::class,
                    'subject_id'   => $boq->id,
                    'after'        => ['type' => $boq->type, 'title' => $boq->title, 'status' => $boq->status],
                ]
            );

            return $boq;
        });
    }

    /**
     * CreateQuotation — create and persist a new Quotation in DRAFT status.
     *
     * CRM integration: getClient() is the authorized B2 contract.
     * Client must be active. Audit: commercial.quotation.created.
     * No lifecycle transitions, no approval, no domain event dispatch in UNIT1.
     *
     * @param array{
     *     title:             string,
     *     tenant_slug:       string,
     *     client_id:         string,
     *     total_value:       string|float,
     *     quotation_date:    string,
     *     validity_date:     string,
     *     quotation_number?: string|null,
     *     tender_id?:        string|null,
     *     boq_id?:           string|null,
     *     notes?:            string|null,
     * } $attributes
     * @throws \InvalidArgumentException On invalid input or inactive client.
     */
    public function createQuotation(array $attributes, string|null $actorId = null): Quotation
    {
        $this->validateQuotationAttributes($attributes);

        // CRM integration: authorized getClient() contract (TASK-004B2 / MEP)
        $client = $this->crmService->getClient($attributes['client_id']);
        if ($client->status !== 'active') {
            throw new \InvalidArgumentException(
                "Client [{$attributes['client_id']}] is not active (status: {$client->status})."
            );
        }

        $quotation = Quotation::create([
            'quotation_number' => $attributes['quotation_number'] ?? null,
            'tender_id'        => $attributes['tender_id'] ?? null,
            'boq_id'           => $attributes['boq_id'] ?? null,
            'client_id'        => $attributes['client_id'],
            'title'            => $attributes['title'] ?? null,
            'total_value'      => $attributes['total_value'] ?? '0',
            'status'           => 'DRAFT',
            'quotation_date'   => $attributes['quotation_date'] ?? null,
            'validity_date'    => $attributes['validity_date'] ?? null,
            'notes'            => $attributes['notes'] ?? null,
            'tenant_slug'      => $attributes['tenant_slug'],
        ]);

        $this->auditService->logEvent(
            'commercial.quotation.created',
            $actorId,
            [
                'tenant_slug'  => $quotation->tenant_slug,
                'subject_type' => Quotation::class,
                'subject_id'   => $quotation->id,
                'after'        => ['title' => $quotation->title, 'status' => $quotation->status],
            ]
        );

        return $quotation;
    }

    /**
     * CreateContract — create and persist a new Contract in DRAFT status.
     *
     * CRM integration: getClient() is the authorized B2 contract.
     * Client must be active. System-managed initial values:
     *   - approved_variations_value = 0
     *   - revised_contract_value    = contract_value
     * Audit: commercial.contract.created.
     * NO signing, NO approval, NO domain event dispatch in UNIT1.
     *
     * @param array{
     *     contract_number:       string,
     *     client_id:             string,
     *     contract_value:        string|float,
     *     start_date:            string,
     *     tenant_slug:           string,
     *     project_id?:           string|null,
     *     quotation_id?:         string|null,
     *     boq_id?:               string|null,
     *     payment_terms?:        string|null,
     *     retention_percentage?: string|float|null,
     *     end_date?:             string|null,
     *     notes?:                string|null,
     * } $attributes
     * @throws \InvalidArgumentException On invalid input or inactive client.
     */
    public function createContract(array $attributes, string|null $actorId = null): Contract
    {
        $this->validateContractAttributes($attributes);

        // CRM integration: authorized getClient() contract (TASK-004B2 / MEP)
        $client = $this->crmService->getClient($attributes['client_id']);
        if ($client->status !== 'active') {
            throw new \InvalidArgumentException(
                "Client [{$attributes['client_id']}] is not active (status: {$client->status})."
            );
        }

        $contractValue = $attributes['contract_value'];

        $contract = Contract::create([
            'contract_number'           => $attributes['contract_number'],
            'client_id'                 => $attributes['client_id'],
            'project_id'                => $attributes['project_id'] ?? null,
            'quotation_id'              => $attributes['quotation_id'] ?? null,
            'boq_id'                    => $attributes['boq_id'] ?? null,
            'contract_value'            => $contractValue,
            'approved_variations_value' => '0',
            'revised_contract_value'    => $contractValue,
            'payment_terms'             => $attributes['payment_terms'] ?? null,
            'retention_percentage'      => $attributes['retention_percentage'] ?? '0',
            'start_date'                => $attributes['start_date'] ?? null,
            'end_date'                  => $attributes['end_date'] ?? null,
            'status'                    => 'DRAFT',
            'notes'                     => $attributes['notes'] ?? null,
            'tenant_slug'               => $attributes['tenant_slug'],
        ]);

        $this->auditService->logEvent(
            'commercial.contract.created',
            $actorId,
            [
                'tenant_slug'  => $contract->tenant_slug,
                'subject_type' => Contract::class,
                'subject_id'   => $contract->id,
                'after'        => [
                    'contract_number' => $contract->contract_number,
                    'status'          => $contract->status,
                    'contract_value'  => $contract->contract_value,
                ],
            ]
        );

        return $contract;
    }

    /**
     * CreateVariation — create and persist a new ContractVariation in DRAFT status.
     *
     * Verifies the referenced contract exists (internal Commercial reference — no CRM call).
     * Audit: commercial.variation.created.
     * NO approval, NO domain event dispatch in UNIT1.
     *
     * @param array{
     *     contract_id:        string,
     *     reason:             string,
     *     tenant_slug:        string,
     *     variation_number?:  string|null,
     *     scope_description?: string|null,
     *     boq_changes?:       array<mixed>|null,
     *     value_change?:      string|float|null,
     *     notes?:             string|null,
     * } $attributes
     * @throws \InvalidArgumentException On invalid input or missing contract.
     */
    public function createVariation(array $attributes, string|null $actorId = null): ContractVariation
    {
        $this->validateVariationAttributes($attributes);

        // Verify contract exists (internal Commercial reference — no CRM call)
        $contract = Contract::find($attributes['contract_id']);
        if ($contract === null) {
            throw new \InvalidArgumentException(
                "Contract [{$attributes['contract_id']}] not found."
            );
        }

        $variation = ContractVariation::create([
            'contract_id'       => $attributes['contract_id'],
            'variation_number'  => $attributes['variation_number'] ?? null,
            'reason'            => $attributes['reason'],
            'scope_description' => $attributes['scope_description'] ?? null,
            'boq_changes'       => $attributes['boq_changes'] ?? null,
            'value_change'      => $attributes['value_change'] ?? '0',
            'status'            => 'DRAFT',
            'notes'             => $attributes['notes'] ?? null,
            'tenant_slug'       => $attributes['tenant_slug'],
        ]);

        $this->auditService->logEvent(
            'commercial.variation.created',
            $actorId,
            [
                'tenant_slug'  => $variation->tenant_slug,
                'subject_type' => ContractVariation::class,
                'subject_id'   => $variation->id,
                'after'        => ['reason' => $variation->reason, 'status' => $variation->status],
            ]
        );

        return $variation;
    }

    // =========================================================================
    // LIFECYCLE TRANSITIONS (TASK-004B2-UNIT2)
    // =========================================================================

    /**
     * AwardTender — transition a Tender to AWARDED status.
     *
     * Lifecycle: DRAFT → PUBLISHED → ... → AWARDED
     * Dispatches: TenderAwarded domain event.
     * Audit: commercial.tender.awarded.
     * No DB transaction needed — single-model update.
     *
     * @throws \InvalidArgumentException If tenderId is empty or Tender not found.
     */
    public function awardTender(string $tenderId, string|null $actorId = null): Tender
    {
        if (empty($tenderId)) {
            throw new \InvalidArgumentException('tenderId must not be empty.');
        }

        $tender = Tender::find($tenderId);
        if ($tender === null) {
            throw new \InvalidArgumentException("Tender [{$tenderId}] not found.");
        }

        $beforeStatus = $tender->status;

        $tender->status     = 'AWARDED';
        $tender->awarded_at = now();
        $tender->save();

        Event::dispatch(new TenderAwarded(
            tenderId:   $tender->id,
            tenantSlug: $tender->tenant_slug,
            occurredAt: new \DateTimeImmutable(),
        ));

        $this->auditService->logEvent(
            'commercial.tender.awarded',
            $actorId,
            [
                'tenant_slug'  => $tender->tenant_slug,
                'subject_type' => Tender::class,
                'subject_id'   => $tender->id,
                'before'       => $beforeStatus,
                'after'        => $tender->status,
            ]
        );

        return $tender;
    }

    /**
     * SignContract — transition a Contract to ACTIVE status (signing activates the contract).
     *
     * Lifecycle: DRAFT → UNDER_REVIEW → APPROVED → ACTIVE
     * Dispatches: ContractSigned domain event.
     * Audit: commercial.contract.signed.
     * No DB transaction needed — single-model update.
     *
     * @throws \InvalidArgumentException If contractId is empty or Contract not found.
     */
    public function signContract(string $contractId, string|null $actorId = null): Contract
    {
        if (empty($contractId)) {
            throw new \InvalidArgumentException('contractId must not be empty.');
        }

        $contract = Contract::find($contractId);
        if ($contract === null) {
            throw new \InvalidArgumentException("Contract [{$contractId}] not found.");
        }

        $beforeStatus = $contract->status;

        $contract->status = 'ACTIVE';
        $contract->save();

        Event::dispatch(new ContractSigned(
            contractId: $contract->id,
            tenantSlug: $contract->tenant_slug,
            occurredAt: new \DateTimeImmutable(),
        ));

        $this->auditService->logEvent(
            'commercial.contract.signed',
            $actorId,
            [
                'tenant_slug'  => $contract->tenant_slug,
                'subject_type' => Contract::class,
                'subject_id'   => $contract->id,
                'before'       => $beforeStatus,
                'after'        => $contract->status,
            ]
        );

        return $contract;
    }

    /**
     * AcceptQuotation — transition a Quotation to ACCEPTED status.
     *
     * Lifecycle: DRAFT → UNDER_REVIEW → APPROVED → SUBMITTED → ACCEPTED
     * Dispatches: QuotationAccepted domain event.
     * Audit: commercial.quotation.accepted.
     * No DB transaction needed — single-model update.
     *
     * @throws \InvalidArgumentException If quotationId is empty or Quotation not found.
     */
    public function acceptQuotation(string $quotationId, string|null $actorId = null): Quotation
    {
        if (empty($quotationId)) {
            throw new \InvalidArgumentException('quotationId must not be empty.');
        }

        $quotation = Quotation::find($quotationId);
        if ($quotation === null) {
            throw new \InvalidArgumentException("Quotation [{$quotationId}] not found.");
        }

        $beforeStatus = $quotation->status;

        $quotation->status      = 'ACCEPTED';
        $quotation->accepted_at = now();
        $quotation->save();

        Event::dispatch(new QuotationAccepted(
            quotationId: $quotation->id,
            tenantSlug:  $quotation->tenant_slug,
            occurredAt:  new \DateTimeImmutable(),
        ));

        $this->auditService->logEvent(
            'commercial.quotation.accepted',
            $actorId,
            [
                'tenant_slug'  => $quotation->tenant_slug,
                'subject_type' => Quotation::class,
                'subject_id'   => $quotation->id,
                'before'       => $beforeStatus,
                'after'        => $quotation->status,
            ]
        );

        return $quotation;
    }

    /**
     * GetVariation — retrieve a ContractVariation by ID.
     *
     * QUERY (CAP-033 — read):
     *  - Input:  variationId (UUID string)
     *  - Output: ContractVariation model
     *  - Failure: throws InvalidArgumentException if not found or empty ID.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @throws \InvalidArgumentException If variationId is empty or ContractVariation not found.
     */
    public function getVariation(string $variationId): ContractVariation
    {
        if (empty($variationId)) {
            throw new \InvalidArgumentException('variationId must not be empty.');
        }

        $variation = ContractVariation::find($variationId);

        if ($variation === null) {
            throw new \InvalidArgumentException("ContractVariation [{$variationId}] not found.");
        }

        return $variation;
    }

    /**
     * ListVariations — retrieve all ContractVariations for a given Contract.
     *
     * QUERY (CAP-033 — list):
     *  - Input:  contractId (UUID string)
     *  - Output: Collection of ContractVariation models ordered by created_at ASC
     *  - Failure: throws InvalidArgumentException if contractId is empty or Contract not found.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ContractVariation>
     * @throws \InvalidArgumentException If contractId is empty or Contract not found.
     */
    public function listVariations(string $contractId): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($contractId)) {
            throw new \InvalidArgumentException('contractId must not be empty.');
        }

        // Verify contract exists
        $contract = Contract::find($contractId);
        if ($contract === null) {
            throw new \InvalidArgumentException("Contract [{$contractId}] not found.");
        }

        return ContractVariation::where('contract_id', $contractId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * RejectVariation — transition a ContractVariation to REJECTED status.
     *
     * Lifecycle: DRAFT | SUBMITTED_TO_CLIENT | CLIENT_REVIEW → REJECTED
     * Audit: commercial.variation.rejected.
     * No domain event dispatched (rejection does not trigger downstream workflows).
     *
     * @throws \InvalidArgumentException If variationId is empty or ContractVariation not found.
     */
    public function rejectVariation(string $variationId, string|null $actorId = null): ContractVariation
    {
        if (empty($variationId)) {
            throw new \InvalidArgumentException('variationId must not be empty.');
        }

        $variation = ContractVariation::find($variationId);
        if ($variation === null) {
            throw new \InvalidArgumentException("ContractVariation [{$variationId}] not found.");
        }

        $beforeStatus = $variation->status;

        $variation->status = 'REJECTED';
        $variation->save();

        $this->auditService->logEvent(
            'commercial.variation.rejected',
            $actorId,
            [
                'tenant_slug'  => $variation->tenant_slug,
                'subject_type' => ContractVariation::class,
                'subject_id'   => $variation->id,
                'before'       => $beforeStatus,
                'after'        => $variation->status,
            ]
        );

        return $variation;
    }

    /**
     * ApproveVariation — transition a ContractVariation to APPROVED status.
     *
     * Lifecycle: DRAFT → SUBMITTED_TO_CLIENT → CLIENT_REVIEW → APPROVED
     * Dispatches: ContractVariationApproved domain event.
     * Audit: commercial.variation.approved.
     * No DB transaction needed — single-model update.
     *
     * @throws \InvalidArgumentException If variationId is empty or ContractVariation not found.
     */
    public function approveVariation(string $variationId, string|null $actorId = null): ContractVariation
    {
        if (empty($variationId)) {
            throw new \InvalidArgumentException('variationId must not be empty.');
        }

        $variation = ContractVariation::find($variationId);
        if ($variation === null) {
            throw new \InvalidArgumentException("ContractVariation [{$variationId}] not found.");
        }

        $beforeStatus = $variation->status;

        $variation->status      = 'APPROVED';
        $variation->approved_at = now();
        $variation->save();

        Event::dispatch(new ContractVariationApproved(
            variationId: $variation->id,
            contractId:  $variation->contract_id,
            tenantSlug:  $variation->tenant_slug,
            occurredAt:  new \DateTimeImmutable(),
        ));

        $this->auditService->logEvent(
            'commercial.variation.approved',
            $actorId,
            [
                'tenant_slug'  => $variation->tenant_slug,
                'subject_type' => ContractVariation::class,
                'subject_id'   => $variation->id,
                'before'       => $beforeStatus,
                'after'        => $variation->status,
            ]
        );

        return $variation;
    }

    /**
     * SubmitQuotationForApproval — submit a Quotation for approval and transition to UNDER_REVIEW.
     *
     * Calls ApprovalService::requestApproval() and transitions Quotation status to UNDER_REVIEW.
     * Wrapped in a DB transaction for atomicity (approval request + status update).
     * Audit: commercial.quotation.submitted_for_approval.
     *
     * @return array{quotation: Quotation, approval_request: \App\Approval\ApprovalRequest}
     * @throws \InvalidArgumentException If quotationId or requestedBy is empty, or Quotation not found.
     */
    public function submitQuotationForApproval(
        string $quotationId,
        string $requestedBy,
        string|null $actorId = null,
    ): array {
        if (empty($quotationId)) {
            throw new \InvalidArgumentException('quotationId must not be empty.');
        }
        if (empty($requestedBy)) {
            throw new \InvalidArgumentException('requestedBy must not be empty.');
        }

        $quotation = Quotation::find($quotationId);
        if ($quotation === null) {
            throw new \InvalidArgumentException("Quotation [{$quotationId}] not found.");
        }

        $beforeStatus = $quotation->status;

        $result = DB::connection('tenant')->transaction(function () use ($quotation, $quotationId, $requestedBy, $actorId, $beforeStatus) {
            $approvalRequest = $this->approvalService->requestApproval(
                Quotation::class,
                $quotationId,
                $requestedBy,
                $quotation->tenant_slug,
            );

            $quotation->status = 'UNDER_REVIEW';
            $quotation->save();

            $this->auditService->logEvent(
                'commercial.quotation.submitted_for_approval',
                $actorId,
                [
                    'tenant_slug'  => $quotation->tenant_slug,
                    'subject_type' => Quotation::class,
                    'subject_id'   => $quotation->id,
                    'before'       => $beforeStatus,
                    'after'        => $quotation->status,
                ]
            );

            return ['quotation' => $quotation, 'approval_request' => $approvalRequest];
        });

        return $result;
    }

    /**
     * SubmitContractForApproval — submit a Contract for approval and transition to UNDER_REVIEW.
     *
     * Calls ApprovalService::requestApproval() and transitions Contract status to UNDER_REVIEW.
     * Wrapped in a DB transaction for atomicity (approval request + status update).
     * Audit: commercial.contract.submitted_for_approval.
     *
     * @return array{contract: Contract, approval_request: \App\Approval\ApprovalRequest}
     * @throws \InvalidArgumentException If contractId or requestedBy is empty, or Contract not found.
     */
    public function submitContractForApproval(
        string $contractId,
        string $requestedBy,
        string|null $actorId = null,
    ): array {
        if (empty($contractId)) {
            throw new \InvalidArgumentException('contractId must not be empty.');
        }
        if (empty($requestedBy)) {
            throw new \InvalidArgumentException('requestedBy must not be empty.');
        }

        $contract = Contract::find($contractId);
        if ($contract === null) {
            throw new \InvalidArgumentException("Contract [{$contractId}] not found.");
        }

        $beforeStatus = $contract->status;

        $result = DB::connection('tenant')->transaction(function () use ($contract, $contractId, $requestedBy, $actorId, $beforeStatus) {
            $approvalRequest = $this->approvalService->requestApproval(
                Contract::class,
                $contractId,
                $requestedBy,
                $contract->tenant_slug,
            );

            $contract->status = 'UNDER_REVIEW';
            $contract->save();

            $this->auditService->logEvent(
                'commercial.contract.submitted_for_approval',
                $actorId,
                [
                    'tenant_slug'  => $contract->tenant_slug,
                    'subject_type' => Contract::class,
                    'subject_id'   => $contract->id,
                    'before'       => $beforeStatus,
                    'after'        => $contract->status,
                ]
            );

            return ['contract' => $contract, 'approval_request' => $approvalRequest];
        });

        return $result;
    }

    // =========================================================================
    // PRIVATE VALIDATION HELPERS
    // =========================================================================

    private function validateTenderAttributes(array $attributes): void
    {
        if (empty($attributes['title'])) {
            throw new \InvalidArgumentException('Tender title is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Tender tenant_slug is required.');
        }
        if (empty($attributes['client_id'])) {
            throw new \InvalidArgumentException('Tender client_id is required.');
        }
    }

    private function validateBOQAttributes(array $attributes): void
    {
        if (empty($attributes['type'])) {
            throw new \InvalidArgumentException('BOQ type is required.');
        }
        if (!in_array($attributes['type'], ['tender_boq', 'contract_boq', 'variation_boq'], true)) {
            throw new \InvalidArgumentException(
                "BOQ type must be 'tender_boq', 'contract_boq', or 'variation_boq'. Got: [{$attributes['type']}]."
            );
        }
        if (empty($attributes['title'])) {
            throw new \InvalidArgumentException('BOQ title is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('BOQ tenant_slug is required.');
        }
    }

    private function validateQuotationAttributes(array $attributes): void
    {
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Quotation tenant_slug is required.');
        }
        if (empty($attributes['client_id'])) {
            throw new \InvalidArgumentException('Quotation client_id is required.');
        }
    }

    private function validateContractAttributes(array $attributes): void
    {
        if (empty($attributes['contract_number'])) {
            throw new \InvalidArgumentException('Contract contract_number is required.');
        }
        if (empty($attributes['client_id'])) {
            throw new \InvalidArgumentException('Contract client_id is required.');
        }
        if (!isset($attributes['contract_value']) || $attributes['contract_value'] === '') {
            throw new \InvalidArgumentException('Contract contract_value is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Contract tenant_slug is required.');
        }
    }

    private function validateVariationAttributes(array $attributes): void
    {
        if (empty($attributes['contract_id'])) {
            throw new \InvalidArgumentException('Variation contract_id is required.');
        }
        if (empty($attributes['reason'])) {
            throw new \InvalidArgumentException('Variation reason is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Variation tenant_slug is required.');
        }
    }

    // =========================================================================
    // LIST OPERATIONS — WEB-001 PHASE 4
    // =========================================================================

    /**
     * ListTenders — paginated list of Tenders in the active tenant schema.
     *
     * Tenant isolation: Tender model uses connection='tenant' (OD-001).
     * No cross-tenant access possible — schema set by SwitchToTenantSchema middleware.
     *
     * WEB-001 PHASE 4 — Tenders List API
     */
    public function listTenders(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Tender::orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * ListContracts — paginated list of Contracts in the active tenant schema.
     *
     * Tenant isolation: Contract model uses connection='tenant' (OD-001).
     * No cross-tenant access possible — schema set by SwitchToTenantSchema middleware.
     *
     * WEB-001 PHASE 4 — Contracts List API
     */
    public function listContracts(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Contract::orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * ListQuotations — paginated list of Quotations in the active tenant schema.
     *
     * Tenant isolation: Quotation model uses connection='tenant' (OD-001).
     * No cross-tenant access possible — schema set by SwitchToTenantSchema middleware.
     *
     * WEB-001 PHASE 4 — Quotations List API
     */
    public function listQuotations(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Quotation::orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * GetQuotation — retrieve a single Quotation by ID.
     *
     * @throws \InvalidArgumentException If quotationId is empty or Quotation not found.
     */
    public function getQuotation(string $quotationId): Quotation
    {
        if (empty($quotationId)) {
            throw new \InvalidArgumentException('quotationId must not be empty.');
        }

        $quotation = Quotation::find($quotationId);

        if ($quotation === null) {
            throw new \InvalidArgumentException("Quotation [{$quotationId}] not found.");
        }

        return $quotation;
    }
}
