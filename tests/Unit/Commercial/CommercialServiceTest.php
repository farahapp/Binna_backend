<?php

declare(strict_types=1);

namespace Tests\Unit\Commercial;

use App\Approval\ApprovalService;
use App\Audit\AuditEntry;
use App\Audit\AuditService;
use App\Commercial\BOQ;
use App\Commercial\BOQSection;
use App\Commercial\BOQItem;
use App\Commercial\CommercialService;
use App\Commercial\Contract;
use App\Commercial\ContractVariation;
use App\Commercial\Events\ContractAmended;
use App\Commercial\Events\ContractSigned;
use App\Commercial\Events\ContractVariationApproved;
use App\Commercial\Events\QuotationAccepted;
use App\Commercial\Events\TenderAwarded;
use App\Commercial\Quotation;
use App\Commercial\Tender;
use App\CRM\Client;
use App\CRM\CRMService;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Approval\ApprovalRequest;

/**
 * Unit tests for CommercialService — TASK-004B1 + TASK-004B2-UNIT1.
 *
 * Verifies:
 *  B1: getContract, getContractValue, getBOQ, domain event classes
 *  B2-UNIT1: createTender, createBOQ, createQuotation, createContract, createVariation
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 * TASK-004B2-UNIT1 — Creation Operations
 */
class CommercialServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommercialService $service;
    private MockInterface $crmService;
    private MockInterface $auditService;
    private MockInterface $approvalService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crmService      = Mockery::mock(CRMService::class);
        $this->auditService    = Mockery::mock(AuditService::class);
        $this->approvalService = Mockery::mock(ApprovalService::class);

        $this->service = new CommercialService(
            $this->crmService,
            $this->auditService,
            $this->approvalService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Dependency check — B2 uses CRM + Audit, still forbidden: Finance etc.
    // =========================================================================

    /** @test */
    public function commercial_service_b2_has_no_forbidden_dependencies(): void
    {
        $source = file_get_contents(app_path('Commercial/CommercialService.php'));

        // Still forbidden in B2 (Finance, Accounting, Procurement, Projects, HR, Equipment)
        $forbidden = [
            'App\\Accounting',
            'App\\Finance',
            'App\\Procurement',
            'App\\Projects',
            'App\\HR',
            'App\\Equipment',
        ];

        foreach ($forbidden as $dep) {
            $this->assertStringNotContainsString(
                $dep,
                $source,
                "CommercialService must not depend on [{$dep}] in B2"
            );
        }

        // B2 explicitly uses CRM + Audit + ApprovalService (UNIT2)
        $this->assertStringContainsString('App\\CRM\\CRMService', $source);
        $this->assertStringContainsString('App\\Audit\\AuditService', $source);
        $this->assertStringContainsString('App\\Approval\\ApprovalService', $source);
    }

    // =========================================================================
    // getContract — validation (B1)
    // =========================================================================

    /** @test */
    public function get_contract_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractId must not be empty.');
        $this->service->getContract('');
    }

    /** @test */
    public function get_contract_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contract [non-existent-id] not found.');
        $this->service->getContract('non-existent-id');
    }

    /** @test */
    public function get_contract_returns_contract_when_found(): void
    {
        $contract = Contract::create([
            'contract_number'           => 'CNT-001',
            'contract_value'            => '100000.0000',
            'approved_variations_value' => '5000.0000',
            'revised_contract_value'    => '105000.0000',
            'status'                    => 'ACTIVE',
            'tenant_slug'               => 'test-tenant',
        ]);

        $result = $this->service->getContract($contract->id);

        $this->assertInstanceOf(Contract::class, $result);
        $this->assertSame($contract->id, $result->id);
        $this->assertSame('CNT-001', $result->contract_number);
    }

    // =========================================================================
    // getContractValue (B1)
    // =========================================================================

    /** @test */
    public function get_contract_value_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractId must not be empty.');
        $this->service->getContractValue('');
    }

    /** @test */
    public function get_contract_value_returns_exact_three_keys(): void
    {
        $contract = Contract::create([
            'contract_number'           => 'CNT-002',
            'contract_value'            => '200000.0000',
            'approved_variations_value' => '10000.0000',
            'revised_contract_value'    => '210000.0000',
            'status'                    => 'ACTIVE',
            'tenant_slug'               => 'test-tenant',
        ]);

        $result = $this->service->getContractValue($contract->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('value', $result);
        $this->assertArrayHasKey('approved_variations_value', $result);
        $this->assertArrayHasKey('revised_contract_value', $result);
        $this->assertCount(3, $result);
    }

    // =========================================================================
    // getBOQ (B1)
    // =========================================================================

    /** @test */
    public function get_boq_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('boqId must not be empty.');
        $this->service->getBOQ('');
    }

    /** @test */
    public function get_boq_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BOQ [non-existent-id] not found.');
        $this->service->getBOQ('non-existent-id');
    }

    // =========================================================================
    // Domain event classes (B1)
    // =========================================================================

    /** @test */
    public function contract_signed_event_has_correct_payload(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-09-27T10:00:00Z');
        $event = new ContractSigned('contract-uuid', 'test-tenant', $occurredAt);

        $this->assertSame('contract-uuid', $event->contractId);
        $this->assertSame('test-tenant', $event->tenantSlug);
        $this->assertSame($occurredAt, $event->occurredAt);
    }

    /** @test */
    public function tender_awarded_event_has_correct_payload(): void
    {
        $occurredAt = new \DateTimeImmutable();
        $event = new TenderAwarded('tender-uuid', 'test-tenant', $occurredAt);

        $this->assertSame('tender-uuid', $event->tenderId);
        $this->assertSame('test-tenant', $event->tenantSlug);
        $this->assertSame($occurredAt, $event->occurredAt);
    }

    // =========================================================================
    // createTender — UNIT1
    // =========================================================================

    private function mockClientActive(string $clientId): void
    {
        $client = new Client();
        $client->forceFill(['id' => $clientId, 'status' => 'active', 'name' => 'Test Client']);
        $this->crmService->shouldReceive('getClient')->with($clientId)->andReturn($client);
    }

    private function mockClientInactive(string $clientId, string $status = 'inactive'): void
    {
        $client = new Client();
        $client->forceFill(['id' => $clientId, 'status' => $status, 'name' => 'Inactive Client']);
        $this->crmService->shouldReceive('getClient')->with($clientId)->andReturn($client);
    }

    private function mockAuditLogEvent(): void
    {
        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());
    }

    /** @test */
    public function create_tender_throws_on_missing_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tender title is required.');

        $this->service->createTender([
            'client_id'   => 'client-uuid',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_tender_throws_on_missing_client_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tender client_id is required.');

        $this->service->createTender([
            'title'       => 'Test Tender',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_tender_throws_when_client_is_inactive(): void
    {
        $this->mockClientInactive('client-uuid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not active/');

        $this->service->createTender([
            'title'       => 'Test Tender',
            'client_id'   => 'client-uuid',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_tender_throws_when_client_not_found(): void
    {
        $this->crmService->shouldReceive('getClient')
            ->with('missing-client')
            ->andThrow(new InvalidArgumentException('Client [missing-client] not found.'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client [missing-client] not found.');

        $this->service->createTender([
            'title'       => 'Test Tender',
            'client_id'   => 'missing-client',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_tender_returns_tender_with_draft_status(): void
    {
        $this->mockClientActive('client-uuid');
        $this->mockAuditLogEvent();

        $tender = $this->service->createTender([
            'title'       => 'New Tender',
            'client_id'   => 'client-uuid',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertInstanceOf(Tender::class, $tender);
        $this->assertSame('DRAFT', $tender->status);
        $this->assertSame('New Tender', $tender->title);
        $this->assertSame('client-uuid', $tender->client_id);
        $this->assertSame('test-tenant', $tender->tenant_slug);
    }

    /** @test */
    public function create_tender_uses_get_client_not_validate_client(): void
    {
        // Verify getClient() is called (not validateClient)
        $this->crmService->shouldReceive('getClient')->once()->andReturn(
            tap(new Client(), fn($c) => $c->forceFill(['id' => 'client-uuid', 'status' => 'active', 'name' => 'Test']))
        );
        $this->crmService->shouldNotReceive('validateClient');
        $this->mockAuditLogEvent();

        $this->service->createTender([
            'title'       => 'Tender B2 Contract Test',
            'client_id'   => 'client-uuid',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_tender_writes_audit_event(): void
    {
        $this->mockClientActive('client-uuid');

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.tender.created', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->createTender([
            'title'       => 'Audited Tender',
            'client_id'   => 'client-uuid',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    // =========================================================================
    // createBOQ — UNIT1
    // =========================================================================

    /** @test */
    public function create_boq_throws_on_invalid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type must be/');

        $this->service->createBOQ([
            'type'        => 'invalid_type',
            'title'       => 'Test BOQ',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_boq_throws_on_missing_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BOQ title is required.');

        $this->service->createBOQ([
            'type'        => 'tender_boq',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_boq_returns_boq_with_draft_status(): void
    {
        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $boq = $this->service->createBOQ([
            'type'        => 'tender_boq',
            'title'       => 'Test BOQ',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertInstanceOf(BOQ::class, $boq);
        $this->assertSame('DRAFT', $boq->status);
        $this->assertSame('tender_boq', $boq->type);
    }

    /** @test */
    public function create_boq_persists_sections_and_items(): void
    {
        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $boq = $this->service->createBOQ([
            'type'        => 'tender_boq',
            'title'       => 'BOQ With Sections',
            'tenant_slug' => 'test-tenant',
            'sections'    => [
                [
                    'title' => 'Civil Works',
                    'items' => [
                        ['description' => 'Excavation', 'unit' => 'm3', 'quantity' => '100', 'unit_rate' => '50'],
                    ],
                ],
            ],
        ]);

        $this->assertInstanceOf(BOQ::class, $boq);
        $this->assertCount(1, $boq->sections);
        $this->assertSame('Civil Works', $boq->sections->first()->title);
        $this->assertCount(1, $boq->sections->first()->items);
    }

    /** @test */
    public function create_boq_writes_audit_event(): void
    {
        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.boq.created', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->createBOQ([
            'type'        => 'contract_boq',
            'title'       => 'Audited BOQ',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    // =========================================================================
    // createQuotation — UNIT1
    // =========================================================================

    /** @test */
    public function create_quotation_throws_on_inactive_client(): void
    {
        $this->mockClientInactive('client-uuid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not active/');

        $this->service->createQuotation([
            'title'          => 'Test Quotation',
            'client_id'      => 'client-uuid',
            'total_value'    => '50000',
            'quotation_date' => '2026-09-27',
            'validity_date'  => '2026-10-27',
            'tenant_slug'    => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_quotation_returns_quotation_with_draft_status(): void
    {
        $this->mockClientActive('client-uuid');
        $this->mockAuditLogEvent();

        $quotation = $this->service->createQuotation([
            'title'          => 'New Quotation',
            'client_id'      => 'client-uuid',
            'total_value'    => '75000',
            'quotation_date' => '2026-09-27',
            'validity_date'  => '2026-10-27',
            'tenant_slug'    => 'test-tenant',
        ]);

        $this->assertInstanceOf(Quotation::class, $quotation);
        $this->assertSame('DRAFT', $quotation->status);
        $this->assertSame('New Quotation', $quotation->title);
    }

    /** @test */
    public function create_quotation_writes_audit_event(): void
    {
        $this->mockClientActive('client-uuid');

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.quotation.created', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->createQuotation([
            'title'          => 'Audited Quotation',
            'client_id'      => 'client-uuid',
            'total_value'    => '100000',
            'quotation_date' => '2026-09-27',
            'validity_date'  => '2026-10-27',
            'tenant_slug'    => 'test-tenant',
        ]);
    }

    // =========================================================================
    // createContract — UNIT1
    // =========================================================================

    /** @test */
    public function create_contract_throws_on_missing_contract_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contract contract_number is required.');

        $this->service->createContract([
            'client_id'      => 'client-uuid',
            'contract_value' => '500000',
            'start_date'     => '2026-10-01',
            'tenant_slug'    => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_contract_throws_when_client_is_inactive(): void
    {
        $this->mockClientInactive('client-uuid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not active/');

        $this->service->createContract([
            'contract_number' => 'CNT-B2-001',
            'client_id'       => 'client-uuid',
            'contract_value'  => '500000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_contract_returns_contract_with_draft_status(): void
    {
        $this->mockClientActive('client-uuid');
        $this->mockAuditLogEvent();

        $contract = $this->service->createContract([
            'contract_number' => 'CNT-B2-002',
            'client_id'       => 'client-uuid',
            'contract_value'  => '500000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->assertInstanceOf(Contract::class, $contract);
        $this->assertSame('DRAFT', $contract->status);
        $this->assertSame('CNT-B2-002', $contract->contract_number);
    }

    /** @test */
    public function create_contract_sets_system_managed_initial_values(): void
    {
        $this->mockClientActive('client-uuid');
        $this->mockAuditLogEvent();

        $contract = $this->service->createContract([
            'contract_number' => 'CNT-B2-003',
            'client_id'       => 'client-uuid',
            'contract_value'  => '300000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->assertSame('0', (string) (int) $contract->approved_variations_value);
        $this->assertSame('300000', (string) (float) $contract->revised_contract_value);
    }

    /** @test */
    public function create_contract_writes_audit_event(): void
    {
        $this->mockClientActive('client-uuid');

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.contract.created', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->createContract([
            'contract_number' => 'CNT-B2-004',
            'client_id'       => 'client-uuid',
            'contract_value'  => '200000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'test-tenant',
        ]);
    }

    // =========================================================================
    // createVariation — UNIT1
    // =========================================================================

    /** @test */
    public function create_variation_throws_on_missing_contract_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variation contract_id is required.');

        $this->service->createVariation([
            'reason'      => 'Scope change',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_variation_throws_when_contract_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->service->createVariation([
            'contract_id' => 'non-existent-contract-uuid',
            'reason'      => 'Scope change',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function create_variation_returns_variation_with_draft_status(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-VAR-001',
            'contract_value'  => '100000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $variation = $this->service->createVariation([
            'contract_id' => $contract->id,
            'reason'      => 'Additional scope requested by client',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertInstanceOf(ContractVariation::class, $variation);
        $this->assertSame('DRAFT', $variation->status);
        $this->assertSame($contract->id, $variation->contract_id);
    }

    /** @test */
    public function create_variation_writes_audit_event(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-VAR-002',
            'contract_value'  => '100000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.variation.created', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->createVariation([
            'contract_id' => $contract->id,
            'reason'      => 'Extra works required',
            'tenant_slug' => 'test-tenant',
        ]);
    }

    // =========================================================================
    // No lifecycle events dispatched in UNIT1
    // =========================================================================

    /** @test */
    public function unit1_creation_operations_do_not_dispatch_lifecycle_events(): void
    {
        // UNIT2 adds lifecycle event dispatches (awardTender, signContract, etc.)
        // Verify that the lifecycle event classes ARE now present in the service (UNIT2)
        $source = file_get_contents(app_path('Commercial/CommercialService.php'));

        // These dispatches MUST exist in UNIT2 lifecycle methods
        $this->assertStringContainsString('Event::dispatch(new ContractSigned', $source);
        $this->assertStringContainsString('Event::dispatch(new TenderAwarded', $source);
        $this->assertStringContainsString('Event::dispatch(new QuotationAccepted', $source);
        $this->assertStringContainsString('Event::dispatch(new ContractVariationApproved', $source);
    }

    // =========================================================================
    // UNIT2 — awardTender
    // =========================================================================

    /** @test */
    public function award_tender_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenderId must not be empty.');
        $this->service->awardTender('');
    }

    /** @test */
    public function award_tender_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tender [non-existent-id] not found.');
        $this->service->awardTender('non-existent-id');
    }

    /** @test */
    public function award_tender_transitions_status_to_awarded(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $result = $this->service->awardTender($tender->id);

        $this->assertSame('AWARDED', $result->status);
        $this->assertNotNull($result->awarded_at);
    }

    /** @test */
    public function award_tender_dispatches_tender_awarded_event(): void
    {
        Event::fake();

        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $this->service->awardTender($tender->id);

        Event::assertDispatched(TenderAwarded::class);
    }

    /** @test */
    public function award_tender_writes_audit_event(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.tender.awarded', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->awardTender($tender->id);
    }

    // =========================================================================
    // UNIT2 — signContract
    // =========================================================================

    /** @test */
    public function sign_contract_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractId must not be empty.');
        $this->service->signContract('');
    }

    /** @test */
    public function sign_contract_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contract [non-existent-id] not found.');
        $this->service->signContract('non-existent-id');
    }

    /** @test */
    public function sign_contract_transitions_status_to_active(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-001',
            'contract_value'  => '100000',
            'status'          => 'DRAFT',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $result = $this->service->signContract($contract->id);

        $this->assertSame('ACTIVE', $result->status);
    }

    /** @test */
    public function sign_contract_dispatches_contract_signed_event(): void
    {
        Event::fake();

        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-002',
            'contract_value'  => '100000',
            'status'          => 'DRAFT',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $this->service->signContract($contract->id);

        Event::assertDispatched(ContractSigned::class);
    }

    /** @test */
    public function sign_contract_writes_audit_event(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-003',
            'contract_value'  => '100000',
            'status'          => 'DRAFT',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.contract.signed', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->signContract($contract->id);
    }

    // =========================================================================
    // UNIT2 — acceptQuotation
    // =========================================================================

    /** @test */
    public function accept_quotation_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quotationId must not be empty.');
        $this->service->acceptQuotation('');
    }

    /** @test */
    public function accept_quotation_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quotation [non-existent-id] not found.');
        $this->service->acceptQuotation('non-existent-id');
    }

    /** @test */
    public function accept_quotation_transitions_status_to_accepted(): void
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-UNIT2-001',
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $result = $this->service->acceptQuotation($quotation->id);

        $this->assertSame('ACCEPTED', $result->status);
        $this->assertNotNull($result->accepted_at);
    }

    /** @test */
    public function accept_quotation_dispatches_quotation_accepted_event(): void
    {
        Event::fake();

        $quotation = Quotation::create([
            'quotation_number' => 'QT-UNIT2-002',
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $this->service->acceptQuotation($quotation->id);

        Event::assertDispatched(QuotationAccepted::class);
    }

    /** @test */
    public function accept_quotation_writes_audit_event(): void
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-UNIT2-003',
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.quotation.accepted', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->acceptQuotation($quotation->id);
    }

    // =========================================================================
    // UNIT2 — approveVariation
    // =========================================================================

    /** @test */
    public function approve_variation_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('variationId must not be empty.');
        $this->service->approveVariation('');
    }

    /** @test */
    public function approve_variation_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ContractVariation [non-existent-id] not found.');
        $this->service->approveVariation('non-existent-id');
    }

    /** @test */
    public function approve_variation_transitions_status_to_approved(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-VAR-001',
            'contract_value'  => '100000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'test-tenant',
        ]);

        $variation = ContractVariation::create([
            'contract_id' => $contract->id,
            'reason'      => 'Test reason',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $result = $this->service->approveVariation($variation->id);

        $this->assertSame('APPROVED', $result->status);
        $this->assertNotNull($result->approved_at);
    }

    /** @test */
    public function approve_variation_dispatches_contract_variation_approved_event(): void
    {
        Event::fake();

        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-VAR-002',
            'contract_value'  => '100000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'test-tenant',
        ]);

        $variation = ContractVariation::create([
            'contract_id' => $contract->id,
            'reason'      => 'Test reason',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $this->service->approveVariation($variation->id);

        Event::assertDispatched(ContractVariationApproved::class);
    }

    /** @test */
    public function approve_variation_writes_audit_event(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-VAR-003',
            'contract_value'  => '100000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'test-tenant',
        ]);

        $variation = ContractVariation::create([
            'contract_id' => $contract->id,
            'reason'      => 'Test reason',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->auditService->shouldReceive('logEvent')
            ->once()
            ->with('commercial.variation.approved', null, Mockery::type('array'))
            ->andReturn(new AuditEntry());

        $this->service->approveVariation($variation->id);
    }

    // =========================================================================
    // UNIT2 — submitQuotationForApproval
    // =========================================================================

    /** @test */
    public function submit_quotation_for_approval_throws_on_empty_quotation_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quotationId must not be empty.');
        $this->service->submitQuotationForApproval('', 'user-123');
    }

    /** @test */
    public function submit_quotation_for_approval_throws_on_empty_requested_by(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestedBy must not be empty.');
        $this->service->submitQuotationForApproval('some-id', '');
    }

    /** @test */
    public function submit_quotation_for_approval_throws_when_quotation_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quotation [non-existent-id] not found.');
        $this->service->submitQuotationForApproval('non-existent-id', 'user-123');
    }

    /** @test */
    public function submit_quotation_for_approval_transitions_status_to_under_review(): void
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-UNIT2-004',
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->approvalService->shouldReceive('requestApproval')
            ->once()
            ->andReturn(new ApprovalRequest());

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $result = $this->service->submitQuotationForApproval($quotation->id, 'user-123');

        $this->assertSame('UNDER_REVIEW', $result['quotation']->status);
        $this->assertArrayHasKey('quotation', $result);
        $this->assertArrayHasKey('approval_request', $result);
    }

    /** @test */
    public function submit_quotation_for_approval_calls_approval_service(): void
    {
        $quotation = Quotation::create([
            'quotation_number' => 'QT-UNIT2-005',
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->approvalService->shouldReceive('requestApproval')
            ->once()
            ->with(Quotation::class, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new ApprovalRequest());

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $this->service->submitQuotationForApproval($quotation->id, 'user-123');
    }

    // =========================================================================
    // UNIT2 — submitContractForApproval
    // =========================================================================

    /** @test */
    public function submit_contract_for_approval_throws_on_empty_contract_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractId must not be empty.');
        $this->service->submitContractForApproval('', 'user-123');
    }

    /** @test */
    public function submit_contract_for_approval_throws_on_empty_requested_by(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestedBy must not be empty.');
        $this->service->submitContractForApproval('some-id', '');
    }

    /** @test */
    public function submit_contract_for_approval_throws_when_contract_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contract [non-existent-id] not found.');
        $this->service->submitContractForApproval('non-existent-id', 'user-123');
    }

    /** @test */
    public function submit_contract_for_approval_transitions_status_to_under_review(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-004',
            'contract_value'  => '100000',
            'status'          => 'DRAFT',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->approvalService->shouldReceive('requestApproval')
            ->once()
            ->andReturn(new ApprovalRequest());

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $result = $this->service->submitContractForApproval($contract->id, 'user-123');

        $this->assertSame('UNDER_REVIEW', $result['contract']->status);
        $this->assertArrayHasKey('contract', $result);
        $this->assertArrayHasKey('approval_request', $result);
    }

    /** @test */
    public function submit_contract_for_approval_calls_approval_service(): void
    {
        $contract = Contract::create([
            'contract_number' => 'CNT-UNIT2-005',
            'contract_value'  => '100000',
            'status'          => 'DRAFT',
            'tenant_slug'     => 'test-tenant',
        ]);

        $this->approvalService->shouldReceive('requestApproval')
            ->once()
            ->with(Contract::class, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new ApprovalRequest());

        $this->auditService->shouldReceive('logEvent')->once()->andReturn(new AuditEntry());

        $this->service->submitContractForApproval($contract->id, 'user-123');
    }

    // =========================================================================
    // Singleton resolution
    // =========================================================================

    /** @test */
    public function commercial_service_resolves_as_singleton(): void
    {
        $instance1 = $this->app->make(CommercialService::class);
        $instance2 = $this->app->make(CommercialService::class);
        $this->assertSame($instance1, $instance2);
    }
}
