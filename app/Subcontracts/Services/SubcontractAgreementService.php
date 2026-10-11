<?php

declare(strict_types=1);

namespace App\Subcontracts\Services;

use App\Approval\ApprovalService;
use App\Approval\ApprovalStatus;
use App\Audit\AuditService;
use App\Subcontracts\Events\SubcontractCompleted;
use App\Subcontracts\Events\SubcontractSigned;
use App\Subcontracts\SubcontractAgreement;
use App\Subcontracts\SubcontractScope;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * SubcontractAgreementService — Application/Service Layer for CAP-080.
 *
 * Architectural constraints (TASK-009B / A1 MOD-10):
 *  - Implements Public Contracts for Subcontract Agreement lifecycle.
 *  - Dispatches Domain Events: SubcontractSigned, SubcontractCompleted.
 *  - Integrates with AuditService per established conventions.
 *  - Integrates with ApprovalService per OD-007 (Shared Approval Service).
 *  - MUST NOT depend on Finance, Accounting, Controllers, Routes, or HTTP layer.
 *  - Tenant context resolved by caller via tenant_slug — never cross-tenant access.
 *  - No tenant_id — isolation is physical (Schema-per-Tenant) per OD-001.
 *  - No Auth::user() in business logic.
 *  - Lifecycle: draft → active → completed | draft/active → cancelled
 *
 * TASK-009B — Subcontract Application/Service Layer (CAP-080)
 */
class SubcontractAgreementService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Agreement List
    // -------------------------------------------------------------------------

    /**
     * listAgreements — return a paginated list of SubcontractAgreements for the current tenant.
     *
     * Tenant isolation is physical (Schema-per-Tenant / OD-001).
     * No tenant_slug filter needed — the active schema is already scoped.
     *
     * WEB-001P — OI-5 resolution.
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listAgreements(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return SubcontractAgreement::orderBy('created_at', 'desc')->paginate($perPage);
    }

    // -------------------------------------------------------------------------
    // Public Contracts
    // -------------------------------------------------------------------------

    /**
     * createAgreement — create a new SubcontractAgreement in draft status.
     *
     * @param array{
     *     tenant_slug: string,
     *     agreement_number: string,
     *     subcontractor_name: string,
     *     title: string,
     *     contract_value: numeric-string|float,
     *     subcontractor_id?: string|null,
     *     project_id?: string|null,
     *     scope_summary?: string|null,
     *     retention_percentage?: numeric-string|float|null,
     *     currency?: string|null,
     *     start_date?: string|null,
     *     end_date?: string|null,
     *     notes?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function createAgreement(array $attributes, string|null $actorId = null): SubcontractAgreement
    {
        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($attributes['agreement_number'])) {
            throw new InvalidArgumentException('agreement_number is required.');
        }
        if (empty($attributes['subcontractor_name'])) {
            throw new InvalidArgumentException('subcontractor_name is required.');
        }
        if (empty($attributes['title'])) {
            throw new InvalidArgumentException('title is required.');
        }
        if (! isset($attributes['contract_value'])) {
            throw new InvalidArgumentException('contract_value is required.');
        }

        $agreement = SubcontractAgreement::create([
            'tenant_slug'          => $attributes['tenant_slug'],
            'agreement_number'     => $attributes['agreement_number'],
            'subcontractor_name'   => $attributes['subcontractor_name'],
            'subcontractor_id'     => $attributes['subcontractor_id'] ?? null,
            'project_id'           => $attributes['project_id'] ?? null,
            'title'                => $attributes['title'],
            'scope_summary'        => $attributes['scope_summary'] ?? null,
            'contract_value'       => $attributes['contract_value'],
            'retention_percentage' => $attributes['retention_percentage'] ?? 0,
            'currency'             => $attributes['currency'] ?? 'SAR',
            'status'               => 'draft',
            'start_date'           => $attributes['start_date'] ?? null,
            'end_date'             => $attributes['end_date'] ?? null,
            'notes'                => $attributes['notes'] ?? null,
        ]);

        $this->auditService->logEvent(
            'subcontract.created',
            $actorId,
            [
                'tenant_slug'  => $attributes['tenant_slug'],
                'subject_type' => SubcontractAgreement::class,
                'subject_id'   => $agreement->id,
                'after'        => [
                    'status'           => 'draft',
                    'agreement_number' => $agreement->agreement_number,
                ],
            ]
        );

        return $agreement;
    }

    /**
     * submitForApproval — submit a draft SubcontractAgreement for approval.
     *
     * Creates an ApprovalRequest via ApprovalService and stores the reference.
     * Agreement status remains 'draft' — activation requires approval to be granted.
     *
     * @throws InvalidArgumentException If agreement not found or not in draft status.
     */
    public function submitForApproval(
        string $agreementId,
        string $requestedBy,
        string $tenantSlug,
    ): SubcontractAgreement {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        if (! $agreement->isDraft()) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] must be in draft status to submit for approval. Current: {$agreement->status}"
            );
        }

        $approvalRequest = $this->approvalService->requestApproval(
            approvableType: SubcontractAgreement::class,
            approvableId:   $agreement->id,
            requestedBy:    $requestedBy,
            tenantSlug:     $tenantSlug,
        );

        $agreement->update([
            'approval_request_id' => $approvalRequest->id,
        ]);

        $this->auditService->logEvent(
            'subcontract.submitted_for_approval',
            $requestedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractAgreement::class,
                'subject_id'   => $agreement->id,
                'after'        => ['approval_request_id' => $approvalRequest->id],
            ]
        );

        return $agreement->fresh();
    }

    /**
     * activateAgreement — transition a SubcontractAgreement from draft to active.
     *
     * Verifies approval (when approval_request_id is present) per architecture.
     * Dispatches SubcontractSigned domain event upon success.
     *
     * @throws InvalidArgumentException If not in draft status or approval not granted.
     */
    public function activateAgreement(
        string $agreementId,
        string $actorId,
        string $tenantSlug,
    ): SubcontractAgreement {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        if (! $agreement->isDraft()) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] must be in draft status to activate. Current: {$agreement->status}"
            );
        }

        // Verify approval requirement when an approval request exists.
        if ($agreement->approval_request_id) {
            $approvalRequest = $this->approvalService->getApprovalStatus(
                $agreement->approval_request_id
            );

            if ($approvalRequest->status !== ApprovalStatus::APPROVED) {
                throw new InvalidArgumentException(
                    "SubcontractAgreement [{$agreementId}] cannot be activated — approval has not been granted. Approval status: {$approvalRequest->status->value}"
                );
            }
        }

        $agreement->update([
            'status'    => 'active',
            'signed_by' => $actorId,
        ]);

        $this->auditService->logEvent(
            'subcontract.activated',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractAgreement::class,
                'subject_id'   => $agreement->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'active'],
            ]
        );

        Event::dispatch(new SubcontractSigned(
            subcontractAgreementId: $agreement->id,
            tenantSlug:             $tenantSlug,
            occurredAt:             new \DateTimeImmutable(),
        ));

        return $agreement->fresh();
    }

    /**
     * completeAgreement — transition an active SubcontractAgreement to completed.
     *
     * Dispatches SubcontractCompleted domain event upon success.
     *
     * @throws InvalidArgumentException If agreement not found or not in active status.
     */
    public function completeAgreement(
        string $agreementId,
        string $actorId,
        string $tenantSlug,
    ): SubcontractAgreement {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        if (! $agreement->isActive()) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] must be in active status to complete. Current: {$agreement->status}"
            );
        }

        $agreement->update(['status' => 'completed']);

        $this->auditService->logEvent(
            'subcontract.completed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractAgreement::class,
                'subject_id'   => $agreement->id,
                'before'       => ['status' => 'active'],
                'after'        => ['status' => 'completed'],
            ]
        );

        Event::dispatch(new SubcontractCompleted(
            subcontractAgreementId: $agreement->id,
            tenantSlug:             $tenantSlug,
            occurredAt:             new \DateTimeImmutable(),
        ));

        return $agreement->fresh();
    }

    /**
     * cancelAgreement — cancel a draft or active SubcontractAgreement.
     *
     * Prevents cancellation from completed or already-cancelled status.
     *
     * @throws InvalidArgumentException If agreement not found or in a terminal status.
     */
    public function cancelAgreement(
        string $agreementId,
        string $actorId,
        string $tenantSlug,
    ): SubcontractAgreement {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        if ($agreement->isCompleted() || $agreement->isCancelled()) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] cannot be cancelled from status: {$agreement->status}"
            );
        }

        $previousStatus = $agreement->status;

        $agreement->update(['status' => 'cancelled']);

        $this->auditService->logEvent(
            'subcontract.cancelled',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractAgreement::class,
                'subject_id'   => $agreement->id,
                'before'       => ['status' => $previousStatus],
                'after'        => ['status' => 'cancelled'],
            ]
        );

        return $agreement->fresh();
    }

    /**
     * getSubcontractValue — public contract returning the contract value of a SubcontractAgreement.
     *
     * Provides a stable public contract for Cost Control and Finance consumers.
     * No Finance integration is created here.
     *
     * @throws InvalidArgumentException If not found.
     */
    public function getSubcontractValue(string $subcontractId): string
    {
        if (empty($subcontractId)) {
            throw new InvalidArgumentException('subcontractId must not be empty.');
        }

        $agreement = $this->findAgreementOrFail($subcontractId);

        return (string) $agreement->contract_value;
    }

    /**
     * addScope — add a scope line item to a SubcontractAgreement.
     *
     * Uses intra-module relationship — no WBS dependency.
     * WBS integration is V2/Future scope.
     *
     * @param array{
     *     description: string,
     *     scope_item_number?: string|null,
     *     unit?: string|null,
     *     quantity?: numeric-string|float|null,
     *     unit_rate?: numeric-string|float|null,
     *     total_amount?: numeric-string|float|null,
     * } $attributes
     * @throws InvalidArgumentException If agreement not found or description is missing.
     */
    public function addScope(
        string $agreementId,
        array $attributes,
        string $tenantSlug,
        string|null $actorId = null,
    ): SubcontractScope {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }
        if (empty($attributes['description'])) {
            throw new InvalidArgumentException('description is required.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        $scope = SubcontractScope::create([
            'tenant_slug'              => $tenantSlug,
            'subcontract_agreement_id' => $agreement->id,
            'scope_item_number'        => $attributes['scope_item_number'] ?? null,
            'description'              => $attributes['description'],
            'unit'                     => $attributes['unit'] ?? null,
            'quantity'                 => $attributes['quantity'] ?? 0,
            'unit_rate'                => $attributes['unit_rate'] ?? 0,
            'total_amount'             => $attributes['total_amount'] ?? 0,
        ]);

        $this->auditService->logEvent(
            'subcontract.scope_added',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractScope::class,
                'subject_id'   => $scope->id,
                'after'        => [
                    'subcontract_agreement_id' => $agreement->id,
                    'description'              => $scope->description,
                ],
            ]
        );

        return $scope;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve a SubcontractAgreement by ID (public read).
     *
     * @throws InvalidArgumentException If not found.
     */
    public function getAgreement(string $agreementId): SubcontractAgreement
    {
        return $this->findAgreementOrFail($agreementId);
    }

    // -------------------------------------------------------------------------

    /**
     * Find a SubcontractAgreement by ID or throw InvalidArgumentException.
     *
     * @throws InvalidArgumentException If not found.
     */
    private function findAgreementOrFail(string $agreementId): SubcontractAgreement
    {
        $agreement = SubcontractAgreement::find($agreementId);

        if ($agreement === null) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] not found."
            );
        }

        return $agreement;
    }
}
