<?php

declare(strict_types=1);

namespace App\Subcontracts\Services;

use App\Approval\ApprovalService;
use App\Audit\AuditService;
use App\Subcontracts\Events\SubcontractCertificateApproved;
use App\Subcontracts\SubcontractAgreement;
use App\Subcontracts\SubcontractCertificate;
use App\Subcontracts\SubcontractProgress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * SubcontractProgressService — Application/Service Layer for CAP-080 (Progress & Certification).
 *
 * Architectural constraints (TASK-009B / A1 MOD-10):
 *  - Implements Public Contracts for Subcontract Progress recording and Certificate lifecycle.
 *  - Dispatches Domain Event: SubcontractCertificateApproved.
 *  - Integrates with AuditService per established conventions.
 *  - Integrates with ApprovalService per OD-007 (Shared Approval Service).
 *  - MUST NOT depend on Finance, Accounting, Controllers, Routes, or HTTP layer.
 *  - No actual payment — 'paid' is a lifecycle state only (Finance boundary).
 *  - Tenant context resolved by caller via tenant_slug — never cross-tenant access.
 *  - No tenant_id — isolation is physical (Schema-per-Tenant) per OD-001.
 *  - No Auth::user() in business logic.
 *  - Cumulative percentage and amount are monotonically non-decreasing.
 *
 * TASK-009B — Subcontract Application/Service Layer (CAP-080)
 */
class SubcontractProgressService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts
    // -------------------------------------------------------------------------

    /**
     * recordProgress — record a progress update for an active SubcontractAgreement.
     *
     * Validates cumulative monotonicity:
     *  - cumulative_percentage must not decrease below the previous record.
     *  - cumulative_amount must not decrease below the previous record.
     *
     * @param array{
     *     progress_period: string,
     *     percentage_complete: numeric-string|float,
     *     cumulative_percentage: numeric-string|float,
     *     amount_certified?: numeric-string|float|null,
     *     cumulative_amount?: numeric-string|float|null,
     *     recorded_by?: string|null,
     *     notes?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If agreement not found, not active, or cumulative validation fails.
     */
    public function recordProgress(
        string $agreementId,
        array $attributes,
        string $tenantSlug,
        string|null $actorId = null,
    ): SubcontractProgress {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }
        if (empty($attributes['progress_period'])) {
            throw new InvalidArgumentException('progress_period is required.');
        }
        if (! isset($attributes['percentage_complete'])) {
            throw new InvalidArgumentException('percentage_complete is required.');
        }
        if (! isset($attributes['cumulative_percentage'])) {
            throw new InvalidArgumentException('cumulative_percentage is required.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        if (! $agreement->isActive()) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] must be active to record progress. Current: {$agreement->status}"
            );
        }

        // Validate cumulative monotonicity against the most recent progress record.
        $lastProgress = SubcontractProgress::where('subcontract_agreement_id', $agreement->id)
            ->orderByDesc('created_at')
            ->first();

        if ($lastProgress !== null) {
            $newCumulativePercentage = (float) $attributes['cumulative_percentage'];
            $prevCumulativePercentage = (float) $lastProgress->cumulative_percentage;

            if ($newCumulativePercentage < $prevCumulativePercentage) {
                throw new InvalidArgumentException(
                    "cumulative_percentage ({$newCumulativePercentage}) must not decrease below the previous value ({$prevCumulativePercentage})."
                );
            }

            if (isset($attributes['cumulative_amount'])) {
                $newCumulativeAmount  = (float) $attributes['cumulative_amount'];
                $prevCumulativeAmount = (float) $lastProgress->cumulative_amount;

                if ($newCumulativeAmount < $prevCumulativeAmount) {
                    throw new InvalidArgumentException(
                        "cumulative_amount ({$newCumulativeAmount}) must not decrease below the previous value ({$prevCumulativeAmount})."
                    );
                }
            }
        }

        $progress = SubcontractProgress::create([
            'tenant_slug'              => $tenantSlug,
            'subcontract_agreement_id' => $agreement->id,
            'progress_period'          => $attributes['progress_period'],
            'percentage_complete'      => $attributes['percentage_complete'],
            'cumulative_percentage'    => $attributes['cumulative_percentage'],
            'amount_certified'         => $attributes['amount_certified'] ?? 0,
            'cumulative_amount'        => $attributes['cumulative_amount'] ?? 0,
            'recorded_by'              => $attributes['recorded_by'] ?? $actorId,
            'notes'                    => $attributes['notes'] ?? null,
        ]);

        $this->auditService->logEvent(
            'subcontract.progress_recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractProgress::class,
                'subject_id'   => $progress->id,
                'after'        => [
                    'subcontract_agreement_id' => $agreement->id,
                    'progress_period'          => $progress->progress_period,
                    'cumulative_percentage'    => $progress->cumulative_percentage,
                ],
            ]
        );

        return $progress;
    }

    /**
     * getSubcontractProgress — public contract returning all progress records for a SubcontractAgreement.
     *
     * Provides a stable public contract for Projects and Cost Control consumers.
     * No downstream integration is created here.
     *
     * @return Collection<int, SubcontractProgress>
     * @throws InvalidArgumentException If agreement not found.
     */
    public function getSubcontractProgress(string $subcontractId): Collection
    {
        if (empty($subcontractId)) {
            throw new InvalidArgumentException('subcontractId must not be empty.');
        }

        $this->findAgreementOrFail($subcontractId);

        return SubcontractProgress::where('subcontract_agreement_id', $subcontractId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * issueCertificate — issue a payment certificate for a SubcontractAgreement.
     *
     * Calculates:
     *  - retention_deducted = certified_amount × (retention_percentage / 100)
     *  - net_payable         = certified_amount − retention_deducted
     *
     * Actual payment is Finance scope — not implemented here.
     *
     * @param array{
     *     certified_amount: numeric-string|float,
     *     certificate_number?: string|null,
     *     subcontract_progress_id?: string|null,
     *     issued_date?: string|null,
     *     notes?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If agreement not found or not active.
     */
    public function issueCertificate(
        string $agreementId,
        array $attributes,
        string $tenantSlug,
        string|null $actorId = null,
    ): SubcontractCertificate {
        if (empty($agreementId)) {
            throw new InvalidArgumentException('agreementId must not be empty.');
        }
        if (! isset($attributes['certified_amount'])) {
            throw new InvalidArgumentException('certified_amount is required.');
        }

        $agreement = $this->findAgreementOrFail($agreementId);

        if (! $agreement->isActive()) {
            throw new InvalidArgumentException(
                "SubcontractAgreement [{$agreementId}] must be active to issue a certificate. Current: {$agreement->status}"
            );
        }

        // Validate progress reference if provided.
        if (! empty($attributes['subcontract_progress_id'])) {
            $progressExists = SubcontractProgress::where('id', $attributes['subcontract_progress_id'])
                ->where('subcontract_agreement_id', $agreement->id)
                ->exists();

            if (! $progressExists) {
                throw new InvalidArgumentException(
                    "SubcontractProgress [{$attributes['subcontract_progress_id']}] not found for agreement [{$agreementId}]."
                );
            }
        }

        $certifiedAmount = (float) $attributes['certified_amount'];
        $retentionPct    = (float) $agreement->retention_percentage;

        $retentionDeducted = round($certifiedAmount * ($retentionPct / 100), 4);
        $netPayable        = round($certifiedAmount - $retentionDeducted, 4);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => $tenantSlug,
            'subcontract_agreement_id'  => $agreement->id,
            'subcontract_progress_id'   => $attributes['subcontract_progress_id'] ?? null,
            'certificate_number'        => $attributes['certificate_number'] ?? null,
            'certified_amount'          => $certifiedAmount,
            'retention_deducted'        => $retentionDeducted,
            'net_payable'               => $netPayable,
            'status'                    => 'draft',
            'issued_date'               => $attributes['issued_date'] ?? null,
            'notes'                     => $attributes['notes'] ?? null,
        ]);

        $this->auditService->logEvent(
            'subcontract.certificate_issued',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractCertificate::class,
                'subject_id'   => $certificate->id,
                'after'        => [
                    'subcontract_agreement_id' => $agreement->id,
                    'certified_amount'         => $certificate->certified_amount,
                    'retention_deducted'       => $certificate->retention_deducted,
                    'net_payable'              => $certificate->net_payable,
                    'status'                   => 'draft',
                ],
            ]
        );

        return $certificate;
    }

    /**
     * approveCertificate — approve a draft SubcontractCertificate.
     *
     * Creates an ApprovalRequest and immediately grants it per ApprovalService pattern.
     * Transitions certificate status to 'approved'.
     * Dispatches SubcontractCertificateApproved domain event upon success.
     *
     * @throws InvalidArgumentException If certificate not found or not in draft status.
     */
    public function approveCertificate(
        string $certificateId,
        string $actorId,
        string $tenantSlug,
    ): SubcontractCertificate {
        if (empty($certificateId)) {
            throw new InvalidArgumentException('certificateId must not be empty.');
        }

        $certificate = $this->findCertificateOrFail($certificateId);

        if (! $certificate->isDraft()) {
            throw new InvalidArgumentException(
                "SubcontractCertificate [{$certificateId}] must be in draft status to approve. Current: {$certificate->status}"
            );
        }

        // Use ApprovalService per the established pattern.
        $approvalRequest = $this->approvalService->requestApproval(
            approvableType: SubcontractCertificate::class,
            approvableId:   $certificate->id,
            requestedBy:    $actorId,
            tenantSlug:     $tenantSlug,
        );

        $this->approvalService->approve(
            approvalRequestId: $approvalRequest->id,
            actorId:           $actorId,
        );

        $certificate->update([
            'status'      => 'approved',
            'approved_by' => $actorId,
        ]);

        $this->auditService->logEvent(
            'subcontract.certificate_approved',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => SubcontractCertificate::class,
                'subject_id'   => $certificate->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'approved', 'approved_by' => $actorId],
            ]
        );

        Event::dispatch(new SubcontractCertificateApproved(
            subcontractCertificateId:  $certificate->id,
            subcontractAgreementId:    $certificate->subcontract_agreement_id,
            tenantSlug:                $tenantSlug,
            occurredAt:                new \DateTimeImmutable(),
        ));

        return $certificate->fresh();
    }

    // -------------------------------------------------------------------------
    // Private helpers
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

    /**
     * Find a SubcontractCertificate by ID or throw InvalidArgumentException.
     *
     * @throws InvalidArgumentException If not found.
     */
    private function findCertificateOrFail(string $certificateId): SubcontractCertificate
    {
        $certificate = SubcontractCertificate::find($certificateId);

        if ($certificate === null) {
            throw new InvalidArgumentException(
                "SubcontractCertificate [{$certificateId}] not found."
            );
        }

        return $certificate;
    }
}
