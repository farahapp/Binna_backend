<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Audit\AuditEntry;
use App\Commercial\BOQ;
use App\Commercial\BOQSection;
use App\Commercial\BOQItem;
use App\Commercial\CommercialService;
use App\Commercial\Contract;
use App\Commercial\ContractVariation;
use App\Commercial\Quotation;
use App\Commercial\Tender;
use App\CRM\Client;
use InvalidArgumentException;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature tests for CommercialService — TASK-004B1 + TASK-004B2-UNIT1.
 *
 * Uses the real service container (real CRMService + AuditService).
 * Verifies persistence via tenant connection.
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 * TASK-004B2-UNIT1 — Creation Operations
 */
class CommercialServiceFeatureTest extends TestCase
{
    use RefreshDatabase;

    private CommercialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CommercialService::class);
    }

    // =========================================================================
    // getContract — feature tests (B1)
    // =========================================================================

    /** @test */
    public function get_contract_returns_contract_from_tenant_connection(): void
    {
        $contract = Contract::create([
            'contract_number'           => 'FEAT-CNT-001',
            'contract_value'            => '500000.0000',
            'approved_variations_value' => '25000.0000',
            'revised_contract_value'    => '525000.0000',
            'status'                    => 'ACTIVE',
            'tenant_slug'               => 'feature-test-tenant',
        ]);

        $result = $this->service->getContract($contract->id);

        $this->assertInstanceOf(Contract::class, $result);
        $this->assertSame($contract->id, $result->id);
        $this->assertSame('FEAT-CNT-001', $result->contract_number);
        $this->assertSame('tenant', $result->getConnectionName());
    }

    /** @test */
    public function get_contract_throws_invalid_argument_exception_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contract [00000000-0000-0000-0000-000000000000] not found.');

        $this->service->getContract('00000000-0000-0000-0000-000000000000');
    }

    /** @test */
    public function get_contract_throws_invalid_argument_exception_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractId must not be empty.');

        $this->service->getContract('');
    }

    // =========================================================================
    // getContractValue — feature tests (B1)
    // =========================================================================

    /** @test */
    public function get_contract_value_returns_correct_structure_from_tenant(): void
    {
        $contract = Contract::create([
            'contract_number'           => 'FEAT-CNT-002',
            'contract_value'            => '750000.0000',
            'approved_variations_value' => '30000.0000',
            'revised_contract_value'    => '780000.0000',
            'status'                    => 'ACTIVE',
            'tenant_slug'               => 'feature-test-tenant',
        ]);

        $result = $this->service->getContractValue($contract->id);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('value', $result);
        $this->assertArrayHasKey('approved_variations_value', $result);
        $this->assertArrayHasKey('revised_contract_value', $result);
        $this->assertCount(3, $result);
    }

    // =========================================================================
    // getBOQ — feature tests (B1)
    // =========================================================================

    /** @test */
    public function get_boq_returns_boq_from_tenant_connection(): void
    {
        $boq = BOQ::create([
            'type'        => 'tender_boq',
            'title'       => 'Feature BOQ',
            'status'      => 'DRAFT',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $result = $this->service->getBOQ($boq->id);

        $this->assertInstanceOf(BOQ::class, $result);
        $this->assertSame($boq->id, $result->id);
        $this->assertSame('tenant', $result->getConnectionName());
    }

    // =========================================================================
    // createTender — feature tests (B2-UNIT1)
    // =========================================================================

    /** @test */
    public function create_tender_persists_to_tenant_connection(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Test Client Ltd',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $tender = $this->service->createTender([
            'title'       => 'Feature Tender',
            'client_id'   => $client->id,
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->assertInstanceOf(Tender::class, $tender);
        $this->assertNotNull($tender->id);
        $this->assertSame('DRAFT', $tender->status);
        $this->assertSame('feature-test-tenant', $tender->tenant_slug);
        $this->assertSame('tenant', $tender->getConnectionName());

        // Verify persisted
        $this->assertDatabaseHas('tenders', [
            'id'    => $tender->id,
            'title' => 'Feature Tender',
        ], 'tenant');
    }

    /** @test */
    public function create_tender_rejects_inactive_client(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Inactive Co',
            'status'      => 'inactive',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not active/');

        $this->service->createTender([
            'title'       => 'Tender With Inactive Client',
            'client_id'   => $client->id,
            'tenant_slug' => 'feature-test-tenant',
        ]);
    }

    /** @test */
    public function create_tender_writes_audit_entry(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Audit Test Client',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->service->createTender([
            'title'       => 'Audited Tender',
            'client_id'   => $client->id,
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'event'       => 'commercial.tender.created',
            'tenant_slug' => 'feature-test-tenant',
        ], 'tenant');
    }

    // =========================================================================
    // createBOQ — feature tests (B2-UNIT1)
    // =========================================================================

    /** @test */
    public function create_boq_persists_boq_to_tenant_connection(): void
    {
        $boq = $this->service->createBOQ([
            'type'        => 'tender_boq',
            'title'       => 'Feature BOQ Creation',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->assertInstanceOf(BOQ::class, $boq);
        $this->assertSame('DRAFT', $boq->status);
        $this->assertDatabaseHas('boqs', ['id' => $boq->id, 'type' => 'tender_boq'], 'tenant');
    }

    /** @test */
    public function create_boq_persists_sections_and_items_atomically(): void
    {
        $boq = $this->service->createBOQ([
            'type'        => 'tender_boq',
            'title'       => 'BOQ With Aggregate',
            'tenant_slug' => 'feature-test-tenant',
            'sections'    => [
                [
                    'title' => 'Civil Works',
                    'items' => [
                        ['description' => 'Excavation', 'unit' => 'm3', 'quantity' => '200', 'unit_rate' => '45', 'amount' => '9000'],
                    ],
                ],
                [
                    'title' => 'MEP Works',
                    'items' => [],
                ],
            ],
        ]);

        $this->assertDatabaseCount('boq_sections', 2, 'tenant');
        $this->assertDatabaseCount('boq_items', 1, 'tenant');
        $this->assertDatabaseHas('boq_sections', ['boq_id' => $boq->id, 'title' => 'Civil Works'], 'tenant');
    }

    /** @test */
    public function create_boq_writes_audit_entry(): void
    {
        $this->service->createBOQ([
            'type'        => 'contract_boq',
            'title'       => 'Audit BOQ',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'event'       => 'commercial.boq.created',
            'tenant_slug' => 'feature-test-tenant',
        ], 'tenant');
    }

    // =========================================================================
    // createQuotation — feature tests (B2-UNIT1)
    // =========================================================================

    /** @test */
    public function create_quotation_persists_to_tenant_connection(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Quotation Client',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $quotation = $this->service->createQuotation([
            'title'          => 'Feature Quotation',
            'client_id'      => $client->id,
            'total_value'    => '120000',
            'quotation_date' => '2026-09-27',
            'validity_date'  => '2026-10-27',
            'tenant_slug'    => 'feature-test-tenant',
        ]);

        $this->assertInstanceOf(Quotation::class, $quotation);
        $this->assertSame('DRAFT', $quotation->status);
        $this->assertDatabaseHas('quotations', ['id' => $quotation->id], 'tenant');
    }

    /** @test */
    public function create_quotation_writes_audit_entry(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Quotation Audit Client',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->service->createQuotation([
            'title'          => 'Audited Quotation',
            'client_id'      => $client->id,
            'total_value'    => '80000',
            'quotation_date' => '2026-09-27',
            'validity_date'  => '2026-10-27',
            'tenant_slug'    => 'feature-test-tenant',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'event'       => 'commercial.quotation.created',
            'tenant_slug' => 'feature-test-tenant',
        ], 'tenant');
    }

    // =========================================================================
    // createContract — feature tests (B2-UNIT1)
    // =========================================================================

    /** @test */
    public function create_contract_persists_to_tenant_connection(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Contract Client',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $contract = $this->service->createContract([
            'contract_number' => 'FEAT-CNT-B2-001',
            'client_id'       => $client->id,
            'contract_value'  => '1000000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'feature-test-tenant',
        ]);

        $this->assertInstanceOf(Contract::class, $contract);
        $this->assertSame('DRAFT', $contract->status);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'contract_number' => 'FEAT-CNT-B2-001'], 'tenant');
    }

    /** @test */
    public function create_contract_sets_approved_variations_to_zero_and_revised_to_value(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Contract Sys Values Client',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $contract = $this->service->createContract([
            'contract_number' => 'FEAT-CNT-B2-002',
            'client_id'       => $client->id,
            'contract_value'  => '500000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'feature-test-tenant',
        ]);

        $this->assertSame('0.0000', $contract->approved_variations_value);
        $this->assertSame('500000.0000', $contract->revised_contract_value);
    }

    /** @test */
    public function create_contract_writes_audit_entry(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Contract Audit Client',
            'status'      => 'active',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->service->createContract([
            'contract_number' => 'FEAT-CNT-B2-003',
            'client_id'       => $client->id,
            'contract_value'  => '250000',
            'start_date'      => '2026-10-01',
            'tenant_slug'     => 'feature-test-tenant',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'event'       => 'commercial.contract.created',
            'tenant_slug' => 'feature-test-tenant',
        ], 'tenant');
    }

    // =========================================================================
    // createVariation — feature tests (B2-UNIT1)
    // =========================================================================

    /** @test */
    public function create_variation_persists_to_tenant_connection(): void
    {
        $contract = Contract::create([
            'contract_number' => 'FEAT-CNT-VAR-001',
            'contract_value'  => '300000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'feature-test-tenant',
        ]);

        $variation = $this->service->createVariation([
            'contract_id' => $contract->id,
            'reason'      => 'Client requested additional scope',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->assertInstanceOf(ContractVariation::class, $variation);
        $this->assertSame('DRAFT', $variation->status);
        $this->assertDatabaseHas('contract_variations', ['id' => $variation->id, 'contract_id' => $contract->id], 'tenant');
    }

    /** @test */
    public function create_variation_throws_when_contract_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->service->createVariation([
            'contract_id' => '00000000-0000-0000-0000-000000000000',
            'reason'      => 'Scope change',
            'tenant_slug' => 'feature-test-tenant',
        ]);
    }

    /** @test */
    public function create_variation_writes_audit_entry(): void
    {
        $contract = Contract::create([
            'contract_number' => 'FEAT-CNT-VAR-002',
            'contract_value'  => '400000',
            'status'          => 'ACTIVE',
            'tenant_slug'     => 'feature-test-tenant',
        ]);

        $this->service->createVariation([
            'contract_id' => $contract->id,
            'reason'      => 'Design changes',
            'tenant_slug' => 'feature-test-tenant',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'event'       => 'commercial.variation.created',
            'tenant_slug' => 'feature-test-tenant',
        ], 'tenant');
    }

    // =========================================================================
    // Singleton
    // =========================================================================

    /** @test */
    public function commercial_service_resolves_as_singleton(): void
    {
        $instance1 = $this->app->make(CommercialService::class);
        $instance2 = $this->app->make(CommercialService::class);
        $this->assertSame($instance1, $instance2);
    }
}
