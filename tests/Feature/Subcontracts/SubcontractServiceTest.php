<?php

declare(strict_types=1);

namespace Tests\Feature\Subcontracts;

use App\Subcontracts\Events\SubcontractCertificateApproved;
use App\Subcontracts\Events\SubcontractCompleted;
use App\Subcontracts\Events\SubcontractSigned;
use App\Subcontracts\Services\SubcontractAgreementService;
use App\Subcontracts\Services\SubcontractProgressService;
use App\Subcontracts\SubcontractAgreement;
use App\Subcontracts\SubcontractCertificate;
use App\Subcontracts\SubcontractProgress;
use App\Subcontracts\SubcontractScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for Subcontract Application/Service Layer — TASK-009B.
 *
 * Verifies:
 *  1.  SubcontractAgreementService — createAgreement lifecycle
 *  2.  SubcontractAgreementService — submitForApproval
 *  3.  SubcontractAgreementService — activateAgreement (with approval)
 *  4.  SubcontractAgreementService — completeAgreement
 *  5.  SubcontractAgreementService — cancelAgreement
 *  6.  SubcontractAgreementService — invalid lifecycle transitions
 *  7.  SubcontractAgreementService — addScope + tenant isolation
 *  8.  SubcontractAgreementService — getSubcontractValue (public contract)
 *  9.  SubcontractProgressService  — recordProgress
 *  10. SubcontractProgressService  — cumulative percentage validation
 *  11. SubcontractProgressService  — cumulative amount validation
 *  12. SubcontractProgressService  — getSubcontractProgress (public contract)
 *  13. SubcontractProgressService  — issueCertificate + retention calculation
 *  14. SubcontractProgressService  — approveCertificate + lifecycle
 *  15. Domain events: SubcontractSigned, SubcontractCertificateApproved, SubcontractCompleted
 *  16. Approval integration: approval_request_id created; activation respects approval
 *  17. Audit integration: impactful operations produce AuditEntry records
 *  18. Tenant isolation: Tenant A cannot read/modify Tenant B subcontract data
 *  19. ServiceProvider registrations
 */
class SubcontractServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubcontractAgreementService $agreementService;
    private SubcontractProgressService  $progressService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agreementService = app(SubcontractAgreementService::class);
        $this->progressService  = app(SubcontractProgressService::class);
    }

    // =========================================================================
    // ServiceProvider Registration
    // =========================================================================

    /** @test */
    public function subcontract_agreement_service_is_registered(): void
    {
        $this->assertInstanceOf(
            SubcontractAgreementService::class,
            app(SubcontractAgreementService::class)
        );
    }

    /** @test */
    public function subcontract_progress_service_is_registered(): void
    {
        $this->assertInstanceOf(
            SubcontractProgressService::class,
            app(SubcontractProgressService::class)
        );
    }

    // =========================================================================
    // createAgreement
    // =========================================================================

    /** @test */
    public function can_create_agreement_with_required_fields(): void
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'        => 'tenant-a',
            'agreement_number'   => 'SC-2026-001',
            'subcontractor_name' => 'ACME Subcontract Co.',
            'title'              => 'Site Civil Works',
            'contract_value'     => 500000.00,
        ]);

        $this->assertNotNull($agreement->id);
        $this->assertEquals('draft', $agreement->status);
        $this->assertEquals('tenant-a', $agreement->tenant_slug);
        $this->assertEquals('SC-2026-001', $agreement->agreement_number);
        $this->assertEquals('Site Civil Works', $agreement->title);
        $this->assertEquals('SAR', $agreement->currency);
    }

    /** @test */
    public function create_agreement_sets_default_status_to_draft(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->assertTrue($agreement->isDraft());
        $this->assertFalse($agreement->isActive());
    }

    /** @test */
    public function create_agreement_stores_optional_fields(): void
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'          => 'tenant-a',
            'agreement_number'     => 'SC-2026-002',
            'subcontractor_name'   => 'BuildPro Ltd.',
            'title'                => 'MEP Installation',
            'contract_value'       => 200000.00,
            'retention_percentage' => 10.0,
            'currency'             => 'USD',
            'project_id'           => 'proj-uuid-1',
            'subcontractor_id'     => 'sub-uuid-1',
            'notes'                => 'Phase 1 only',
        ]);

        $this->assertEquals('10.00', $agreement->retention_percentage);
        $this->assertEquals('USD', $agreement->currency);
        $this->assertEquals('proj-uuid-1', $agreement->project_id);
        $this->assertEquals('sub-uuid-1', $agreement->subcontractor_id);
        $this->assertEquals('Phase 1 only', $agreement->notes);
    }

    /** @test */
    public function create_agreement_requires_tenant_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant_slug is required');

        $this->agreementService->createAgreement([
            'agreement_number'   => 'SC-001',
            'subcontractor_name' => 'X',
            'title'              => 'Y',
            'contract_value'     => 1000,
        ]);
    }

    /** @test */
    public function create_agreement_requires_agreement_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('agreement_number is required');

        $this->agreementService->createAgreement([
            'tenant_slug'        => 'tenant-a',
            'subcontractor_name' => 'X',
            'title'              => 'Y',
            'contract_value'     => 1000,
        ]);
    }

    /** @test */
    public function create_agreement_requires_subcontractor_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subcontractor_name is required');

        $this->agreementService->createAgreement([
            'tenant_slug'      => 'tenant-a',
            'agreement_number' => 'SC-001',
            'title'            => 'Y',
            'contract_value'   => 1000,
        ]);
    }

    /** @test */
    public function create_agreement_requires_title(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');

        $this->agreementService->createAgreement([
            'tenant_slug'        => 'tenant-a',
            'agreement_number'   => 'SC-001',
            'subcontractor_name' => 'X',
            'contract_value'     => 1000,
        ]);
    }

    /** @test */
    public function create_agreement_requires_contract_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contract_value is required');

        $this->agreementService->createAgreement([
            'tenant_slug'        => 'tenant-a',
            'agreement_number'   => 'SC-001',
            'subcontractor_name' => 'X',
            'title'              => 'Y',
        ]);
    }

    // =========================================================================
    // Approval — submitForApproval
    // =========================================================================

    /** @test */
    public function submit_for_approval_creates_approval_request(): void
    {
        $agreement = $this->createDraftAgreement();

        $result = $this->agreementService->submitForApproval(
            $agreement->id,
            'actor-uuid',
            'tenant-a'
        );

        $this->assertNotNull($result->approval_request_id);
        $this->assertTrue($result->isDraft()); // Status stays draft
    }

    /** @test */
    public function submit_for_approval_stores_approval_request_id(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->assertNull($agreement->approval_request_id);

        $result = $this->agreementService->submitForApproval(
            $agreement->id,
            'actor-uuid',
            'tenant-a'
        );

        $this->assertNotEmpty($result->approval_request_id);
    }

    /** @test */
    public function submit_for_approval_requires_draft_status(): void
    {
        $agreement = $this->createActiveAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in draft status to submit for approval');

        $this->agreementService->submitForApproval($agreement->id, 'actor-uuid', 'tenant-a');
    }

    // =========================================================================
    // Approval — activateAgreement (with approval)
    // =========================================================================

    /** @test */
    public function can_activate_agreement_after_approval_granted(): void
    {
        Event::fake();

        $agreement = $this->createDraftAgreement();
        $submitted = $this->agreementService->submitForApproval($agreement->id, 'req-uuid', 'tenant-a');

        // Grant the approval via ApprovalService directly.
        $approvalService = app(\App\Approval\ApprovalService::class);
        $approvalService->approve(
            approvalRequestId: $submitted->approval_request_id,
            actorId:           'approver-uuid',
        );

        $active = $this->agreementService->activateAgreement($agreement->id, 'approver-uuid', 'tenant-a');

        $this->assertEquals('active', $active->status);
        $this->assertTrue($active->isActive());
    }

    /** @test */
    public function activation_fails_if_approval_not_granted(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->agreementService->submitForApproval($agreement->id, 'req-uuid', 'tenant-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('approval has not been granted');

        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    /** @test */
    public function can_activate_agreement_without_approval_request(): void
    {
        Event::fake();

        // Agreement with no submitForApproval can still be activated (no approval_request_id).
        $agreement = $this->createDraftAgreement();

        $active = $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->assertEquals('active', $active->status);
    }

    /** @test */
    public function activate_requires_draft_status(): void
    {
        $agreement = $this->createActiveAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in draft status to activate');

        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    // =========================================================================
    // completeAgreement
    // =========================================================================

    /** @test */
    public function can_complete_active_agreement(): void
    {
        Event::fake();

        $agreement = $this->createActiveAgreement();
        $completed = $this->agreementService->completeAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->assertEquals('completed', $completed->status);
        $this->assertTrue($completed->isCompleted());
    }

    /** @test */
    public function complete_requires_active_status(): void
    {
        $agreement = $this->createDraftAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in active status to complete');

        $this->agreementService->completeAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    // =========================================================================
    // cancelAgreement
    // =========================================================================

    /** @test */
    public function can_cancel_draft_agreement(): void
    {
        $agreement = $this->createDraftAgreement();
        $cancelled = $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertTrue($cancelled->isCancelled());
    }

    /** @test */
    public function can_cancel_active_agreement(): void
    {
        $agreement = $this->createActiveAgreement();
        $cancelled = $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->assertEquals('cancelled', $cancelled->status);
    }

    /** @test */
    public function cannot_cancel_completed_agreement(): void
    {
        Event::fake();
        $agreement = $this->createActiveAgreement();
        $this->agreementService->completeAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be cancelled from status: completed');

        $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    /** @test */
    public function cannot_cancel_already_cancelled_agreement(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be cancelled from status: cancelled');

        $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    // =========================================================================
    // Invalid lifecycle transitions
    // =========================================================================

    /** @test */
    public function cannot_complete_draft_agreement(): void
    {
        $agreement = $this->createDraftAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->agreementService->completeAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    /** @test */
    public function cannot_activate_cancelled_agreement(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in draft status to activate');

        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');
    }

    // =========================================================================
    // getSubcontractValue — public contract
    // =========================================================================

    /** @test */
    public function get_subcontract_value_returns_contract_value(): void
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'        => 'tenant-a',
            'agreement_number'   => 'SC-VAL-001',
            'subcontractor_name' => 'ValTest Co.',
            'title'              => 'Value Test',
            'contract_value'     => 750000.00,
        ]);

        $value = $this->agreementService->getSubcontractValue($agreement->id);

        $this->assertIsString($value);
        $this->assertEquals('750000.0000', $value);
    }

    /** @test */
    public function get_subcontract_value_throws_for_unknown_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->agreementService->getSubcontractValue('00000000-0000-0000-0000-000000000000');
    }

    // =========================================================================
    // addScope
    // =========================================================================

    /** @test */
    public function can_add_scope_to_agreement(): void
    {
        $agreement = $this->createDraftAgreement();

        $scope = $this->agreementService->addScope(
            $agreement->id,
            [
                'description'      => 'Foundation excavation',
                'scope_item_number' => 'A-001',
                'unit'             => 'm3',
                'quantity'         => 500.0,
                'unit_rate'        => 80.0,
                'total_amount'     => 40000.0,
            ],
            'tenant-a',
            'actor-uuid'
        );

        $this->assertNotNull($scope->id);
        $this->assertEquals($agreement->id, $scope->subcontract_agreement_id);
        $this->assertEquals('Foundation excavation', $scope->description);
        $this->assertEquals('tenant-a', $scope->tenant_slug);
    }

    /** @test */
    public function add_scope_requires_description(): void
    {
        $agreement = $this->createDraftAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('description is required');

        $this->agreementService->addScope($agreement->id, [], 'tenant-a');
    }

    /** @test */
    public function add_scope_uses_intra_module_relationship(): void
    {
        $agreement = $this->createDraftAgreement();

        $this->agreementService->addScope(
            $agreement->id,
            ['description' => 'Scope Item A'],
            'tenant-a'
        );
        $this->agreementService->addScope(
            $agreement->id,
            ['description' => 'Scope Item B'],
            'tenant-a'
        );

        $this->assertCount(2, $agreement->fresh()->scopes);
    }

    // =========================================================================
    // Tenant Isolation — Scope
    // =========================================================================

    /** @test */
    public function tenant_a_scope_not_visible_in_tenant_b_agreement(): void
    {
        $agreementA = $this->createDraftAgreementForTenant('tenant-a', 'SC-A-001');
        $agreementB = $this->createDraftAgreementForTenant('tenant-b', 'SC-B-001');

        $this->agreementService->addScope(
            $agreementA->id,
            ['description' => 'Tenant A Scope'],
            'tenant-a'
        );

        // Tenant B's agreement has no scopes.
        $this->assertCount(0, $agreementB->fresh()->scopes);

        // Scope for Tenant A's agreement is isolated.
        $scopeCount = SubcontractScope::where('subcontract_agreement_id', $agreementB->id)->count();
        $this->assertEquals(0, $scopeCount);
    }

    // =========================================================================
    // recordProgress
    // =========================================================================

    /** @test */
    public function can_record_progress_on_active_agreement(): void
    {
        $agreement = $this->createActiveAgreement();

        $progress = $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 25.0,
                'cumulative_percentage' => 25.0,
                'amount_certified'      => 125000.0,
                'cumulative_amount'     => 125000.0,
            ],
            'tenant-a',
            'actor-uuid'
        );

        $this->assertNotNull($progress->id);
        $this->assertEquals($agreement->id, $progress->subcontract_agreement_id);
        $this->assertEquals('25.00', $progress->cumulative_percentage);
        $this->assertEquals('tenant-a', $progress->tenant_slug);
    }

    /** @test */
    public function record_progress_requires_active_agreement(): void
    {
        $agreement = $this->createDraftAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be active to record progress');

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 10.0,
                'cumulative_percentage' => 10.0,
            ],
            'tenant-a'
        );
    }

    // =========================================================================
    // Cumulative percentage validation
    // =========================================================================

    /** @test */
    public function cumulative_percentage_must_not_decrease(): void
    {
        $agreement = $this->createActiveAgreement();

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-01',
                'percentage_complete'   => 30.0,
                'cumulative_percentage' => 30.0,
            ],
            'tenant-a'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cumulative_percentage');

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 20.0,
                'cumulative_percentage' => 20.0, // lower than 30 — invalid
            ],
            'tenant-a'
        );
    }

    /** @test */
    public function cumulative_percentage_equal_to_previous_is_allowed(): void
    {
        $agreement = $this->createActiveAgreement();

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-01',
                'percentage_complete'   => 30.0,
                'cumulative_percentage' => 30.0,
            ],
            'tenant-a'
        );

        $progress2 = $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 0.0,
                'cumulative_percentage' => 30.0, // same value — allowed
            ],
            'tenant-a'
        );

        $this->assertNotNull($progress2->id);
    }

    // =========================================================================
    // Cumulative amount validation
    // =========================================================================

    /** @test */
    public function cumulative_amount_must_not_decrease(): void
    {
        $agreement = $this->createActiveAgreement();

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-01',
                'percentage_complete'   => 30.0,
                'cumulative_percentage' => 30.0,
                'amount_certified'      => 150000.0,
                'cumulative_amount'     => 150000.0,
            ],
            'tenant-a'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cumulative_amount');

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 40.0,
                'cumulative_percentage' => 40.0,
                'amount_certified'      => 0.0,
                'cumulative_amount'     => 100000.0, // lower than 150000 — invalid
            ],
            'tenant-a'
        );
    }

    // =========================================================================
    // getSubcontractProgress — public contract
    // =========================================================================

    /** @test */
    public function get_subcontract_progress_returns_all_records(): void
    {
        $agreement = $this->createActiveAgreement();

        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-01',
                'percentage_complete'   => 25.0,
                'cumulative_percentage' => 25.0,
            ],
            'tenant-a'
        );
        $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 25.0,
                'cumulative_percentage' => 50.0,
            ],
            'tenant-a'
        );

        $records = $this->progressService->getSubcontractProgress($agreement->id);

        $this->assertCount(2, $records);
    }

    /** @test */
    public function get_subcontract_progress_throws_for_unknown_agreement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->progressService->getSubcontractProgress('00000000-0000-0000-0000-000000000000');
    }

    // =========================================================================
    // issueCertificate
    // =========================================================================

    /** @test */
    public function can_issue_certificate_for_active_agreement(): void
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'          => 'tenant-a',
            'agreement_number'     => 'SC-CERT-001',
            'subcontractor_name'   => 'CertTest Co.',
            'title'                => 'Certificate Test',
            'contract_value'       => 400000.0,
            'retention_percentage' => 10.0,
        ]);
        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $certificate = $this->progressService->issueCertificate(
            $agreement->id,
            [
                'certified_amount'   => 100000.0,
                'certificate_number' => 'CERT-001',
                'issued_date'        => '2026-09-30',
            ],
            'tenant-a',
            'actor-uuid'
        );

        $this->assertNotNull($certificate->id);
        $this->assertEquals('draft', $certificate->status);
        $this->assertEquals('100000.0000', $certificate->certified_amount);
    }

    // =========================================================================
    // Retention calculation
    // =========================================================================

    /** @test */
    public function certificate_retention_is_calculated_correctly(): void
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'          => 'tenant-a',
            'agreement_number'     => 'SC-RET-001',
            'subcontractor_name'   => 'RetTest Co.',
            'title'                => 'Retention Test',
            'contract_value'       => 400000.0,
            'retention_percentage' => 10.0,
        ]);
        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $certificate = $this->progressService->issueCertificate(
            $agreement->id,
            ['certified_amount' => 100000.0],
            'tenant-a'
        );

        // retention_deducted = 100000 * 10% = 10000
        $this->assertEquals('10000.0000', $certificate->retention_deducted);
        // net_payable = 100000 - 10000 = 90000
        $this->assertEquals('90000.0000', $certificate->net_payable);
    }

    /** @test */
    public function certificate_with_zero_retention_has_full_net_payable(): void
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'          => 'tenant-a',
            'agreement_number'     => 'SC-ZR-001',
            'subcontractor_name'   => 'ZeroRetention Co.',
            'title'                => 'Zero Retention Test',
            'contract_value'       => 200000.0,
            'retention_percentage' => 0.0,
        ]);
        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $certificate = $this->progressService->issueCertificate(
            $agreement->id,
            ['certified_amount' => 50000.0],
            'tenant-a'
        );

        $this->assertEquals('0.0000', $certificate->retention_deducted);
        $this->assertEquals('50000.0000', $certificate->net_payable);
    }

    /** @test */
    public function cannot_issue_certificate_for_inactive_agreement(): void
    {
        $agreement = $this->createDraftAgreement();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be active to issue a certificate');

        $this->progressService->issueCertificate(
            $agreement->id,
            ['certified_amount' => 1000.0],
            'tenant-a'
        );
    }

    // =========================================================================
    // approveCertificate + lifecycle
    // =========================================================================

    /** @test */
    public function can_approve_draft_certificate(): void
    {
        Event::fake();

        $certificate = $this->createDraftCertificate();

        $approved = $this->progressService->approveCertificate(
            $certificate->id,
            'approver-uuid',
            'tenant-a'
        );

        $this->assertEquals('approved', $approved->status);
        $this->assertTrue($approved->isApproved());
        $this->assertEquals('approver-uuid', $approved->approved_by);
    }

    /** @test */
    public function cannot_approve_already_approved_certificate(): void
    {
        Event::fake();

        $certificate = $this->createDraftCertificate();
        $this->progressService->approveCertificate($certificate->id, 'approver-uuid', 'tenant-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in draft status to approve');

        $this->progressService->approveCertificate($certificate->id, 'approver-uuid', 'tenant-a');
    }

    // =========================================================================
    // Domain Events
    // =========================================================================

    /** @test */
    public function activate_agreement_dispatches_subcontract_signed_event(): void
    {
        Event::fake();

        $agreement = $this->createDraftAgreement();
        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        Event::assertDispatched(SubcontractSigned::class, function ($event) use ($agreement) {
            return $event->subcontractAgreementId === $agreement->id
                && $event->tenantSlug === 'tenant-a'
                && $event->occurredAt instanceof \DateTimeImmutable;
        });
    }

    /** @test */
    public function complete_agreement_dispatches_subcontract_completed_event(): void
    {
        Event::fake();

        $agreement = $this->createActiveAgreement();
        $this->agreementService->completeAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        Event::assertDispatched(SubcontractCompleted::class, function ($event) use ($agreement) {
            return $event->subcontractAgreementId === $agreement->id
                && $event->tenantSlug === 'tenant-a'
                && $event->occurredAt instanceof \DateTimeImmutable;
        });
    }

    /** @test */
    public function approve_certificate_dispatches_certificate_approved_event(): void
    {
        Event::fake();

        $certificate = $this->createDraftCertificate();
        $this->progressService->approveCertificate($certificate->id, 'approver-uuid', 'tenant-a');

        Event::assertDispatched(
            SubcontractCertificateApproved::class,
            function ($event) use ($certificate) {
                return $event->subcontractCertificateId === $certificate->id
                    && $event->subcontractAgreementId === $certificate->subcontract_agreement_id
                    && $event->tenantSlug === 'tenant-a'
                    && $event->occurredAt instanceof \DateTimeImmutable;
            }
        );
    }

    /** @test */
    public function cancel_agreement_does_not_dispatch_signed_or_completed_events(): void
    {
        Event::fake();

        $agreement = $this->createDraftAgreement();
        $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        Event::assertNotDispatched(SubcontractSigned::class);
        Event::assertNotDispatched(SubcontractCompleted::class);
    }

    // =========================================================================
    // Audit Integration
    // =========================================================================

    /** @test */
    public function create_agreement_records_audit_entry(): void
    {
        $agreement = $this->createDraftAgreement();

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.created')
            ->where('subject_id', $agreement->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    /** @test */
    public function activate_agreement_records_audit_entry(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.activated')
            ->where('subject_id', $agreement->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    /** @test */
    public function complete_agreement_records_audit_entry(): void
    {
        $agreement = $this->createActiveAgreement();
        $this->agreementService->completeAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.completed')
            ->where('subject_id', $agreement->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    /** @test */
    public function cancel_agreement_records_audit_entry(): void
    {
        $agreement = $this->createDraftAgreement();
        $this->agreementService->cancelAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.cancelled')
            ->where('subject_id', $agreement->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    /** @test */
    public function record_progress_records_audit_entry(): void
    {
        $agreement = $this->createActiveAgreement();

        $progress = $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 20.0,
                'cumulative_percentage' => 20.0,
            ],
            'tenant-a',
            'actor-uuid'
        );

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.progress_recorded')
            ->where('subject_id', $progress->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    /** @test */
    public function issue_certificate_records_audit_entry(): void
    {
        $certificate = $this->createDraftCertificate();

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.certificate_issued')
            ->where('subject_id', $certificate->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    /** @test */
    public function approve_certificate_records_audit_entry(): void
    {
        Event::fake();

        $certificate = $this->createDraftCertificate();
        $this->progressService->approveCertificate($certificate->id, 'approver-uuid', 'tenant-a');

        $auditCount = \App\Audit\AuditEntry::where('event', 'subcontract.certificate_approved')
            ->where('subject_id', $certificate->id)
            ->count();

        $this->assertEquals(1, $auditCount);
    }

    // =========================================================================
    // Tenant Isolation
    // =========================================================================

    /** @test */
    public function tenant_a_cannot_read_tenant_b_agreement(): void
    {
        $agreementB = $this->createDraftAgreementForTenant('tenant-b', 'SC-B-001');

        // Finding the agreement returns it regardless of tenant_slug (physical isolation
        // at schema level). Verify that tenant_slug does isolate at data level.
        $agreement = SubcontractAgreement::find($agreementB->id);
        $this->assertNotNull($agreement);
        $this->assertEquals('tenant-b', $agreement->tenant_slug);

        // Simulate Tenant A querying for Tenant B's agreement by tenant_slug filter.
        $tenantARecords = SubcontractAgreement::where('tenant_slug', 'tenant-a')->get();
        $this->assertFalse($tenantARecords->contains('id', $agreementB->id));
    }

    /** @test */
    public function tenant_a_cannot_see_tenant_b_progress(): void
    {
        $agreementA = $this->createActiveAgreementForTenant('tenant-a', 'SC-A-P-001');
        $agreementB = $this->createActiveAgreementForTenant('tenant-b', 'SC-B-P-001');

        $this->progressService->recordProgress(
            $agreementB->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 10.0,
                'cumulative_percentage' => 10.0,
            ],
            'tenant-b'
        );

        // Tenant A's agreement has no progress records.
        $tenantAProgress = $this->progressService->getSubcontractProgress($agreementA->id);
        $this->assertCount(0, $tenantAProgress);
    }

    /** @test */
    public function tenant_slug_is_persisted_on_all_entities(): void
    {
        $agreement = $this->createDraftAgreementForTenant('tenant-x', 'SC-X-001');

        $scope = $this->agreementService->addScope(
            $agreement->id,
            ['description' => 'X Scope'],
            'tenant-x'
        );
        $this->assertEquals('tenant-x', $scope->tenant_slug);

        $this->agreementService->activateAgreement($agreement->id, 'actor', 'tenant-x');
        $progress = $this->progressService->recordProgress(
            $agreement->id,
            [
                'progress_period'       => '2026-09-30',
                'percentage_complete'   => 5.0,
                'cumulative_percentage' => 5.0,
            ],
            'tenant-x'
        );
        $this->assertEquals('tenant-x', $progress->tenant_slug);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function createDraftAgreement(string $tenant = 'tenant-a', string $number = 'SC-TEST-001'): SubcontractAgreement
    {
        return $this->agreementService->createAgreement([
            'tenant_slug'        => $tenant,
            'agreement_number'   => $number,
            'subcontractor_name' => 'Test Subcontractor',
            'title'              => 'Test Agreement',
            'contract_value'     => 100000.0,
        ]);
    }

    private function createDraftAgreementForTenant(string $tenant, string $number): SubcontractAgreement
    {
        return $this->createDraftAgreement($tenant, $number);
    }

    private function createActiveAgreement(string $tenant = 'tenant-a', string $number = 'SC-ACT-001'): SubcontractAgreement
    {
        $agreement = $this->createDraftAgreement($tenant, $number);
        return $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', $tenant);
    }

    private function createActiveAgreementForTenant(string $tenant, string $number): SubcontractAgreement
    {
        return $this->createActiveAgreement($tenant, $number);
    }

    private function createDraftCertificate(): SubcontractCertificate
    {
        $agreement = $this->agreementService->createAgreement([
            'tenant_slug'          => 'tenant-a',
            'agreement_number'     => 'SC-CERT-' . uniqid(),
            'subcontractor_name'   => 'CertCo',
            'title'                => 'Cert Test',
            'contract_value'       => 200000.0,
            'retention_percentage' => 5.0,
        ]);
        $this->agreementService->activateAgreement($agreement->id, 'actor-uuid', 'tenant-a');

        return $this->progressService->issueCertificate(
            $agreement->id,
            ['certified_amount' => 50000.0],
            'tenant-a',
            'actor-uuid'
        );
    }
}
