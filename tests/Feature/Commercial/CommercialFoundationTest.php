<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Commercial\BOQ;
use App\Commercial\BOQItem;
use App\Commercial\BOQSection;
use App\Commercial\Contract;
use App\Commercial\ContractVariation;
use App\Commercial\Estimate;
use App\Commercial\Quotation;
use App\Commercial\Tender;
use App\Commercial\TenderInvitation;
use App\Commercial\TenderResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Commercial Domain/Data Foundation — TASK-004A.
 *
 * Verifies:
 *  1. All 10 Commercial tables exist in tenant schema
 *  2. Commercial migrations are in the correct path
 *  3. Model persistence works for key entities
 *  4. Relationships work correctly
 *  5. JSON fields persist correctly
 *  6. Tenant isolation — no tenant_id column
 */
class CommercialFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tenders_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('tenders'),
            'tenders table must exist in the tenant schema'
        );
    }

    /** @test */
    public function tender_invitations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('tender_invitations'),
            'tender_invitations table must exist in the tenant schema'
        );
    }

    /** @test */
    public function tender_responses_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('tender_responses'),
            'tender_responses table must exist in the tenant schema'
        );
    }

    /** @test */
    public function boqs_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('boqs'),
            'boqs table must exist in the tenant schema'
        );
    }

    /** @test */
    public function boq_sections_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('boq_sections'),
            'boq_sections table must exist in the tenant schema'
        );
    }

    /** @test */
    public function boq_items_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('boq_items'),
            'boq_items table must exist in the tenant schema'
        );
    }

    /** @test */
    public function estimates_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('estimates'),
            'estimates table must exist in the tenant schema'
        );
    }

    /** @test */
    public function quotations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('quotations'),
            'quotations table must exist in the tenant schema'
        );
    }

    /** @test */
    public function contracts_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('contracts'),
            'contracts table must exist in the tenant schema'
        );
    }

    /** @test */
    public function contract_variations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('contract_variations'),
            'contract_variations table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function commercial_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100001_create_tenders_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100002_create_tender_invitations_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100003_create_tender_responses_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100004_create_boqs_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100005_create_boq_sections_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100006_create_boq_items_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100007_create_estimates_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100008_create_quotations_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100009_create_contracts_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100010_create_contract_variations_table.php')
        );
    }

    /** @test */
    public function commercial_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_27_100001_create_tenders_table.php'),
            'Commercial migrations must NOT be in the shared migration path'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tenders_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('tenders', 'tenant_id'),
            'tenders table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function contracts_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('contracts', 'tenant_id'),
            'contracts table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function tenders_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('tenders', 'tenant_slug'),
            'tenders table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function contracts_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('contracts', 'tenant_slug'),
            'contracts table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Model Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tender_can_be_persisted(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender — Road Construction',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($tender->id);
        $this->assertSame('Test Tender — Road Construction', $tender->title);
        $this->assertSame('DRAFT', $tender->status);
        $this->assertSame('test-tenant', $tender->tenant_slug);
    }

    /** @test */
    public function tender_invitation_can_be_persisted_with_tender(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'PUBLISHED',
            'tenant_slug' => 'test-tenant',
        ]);

        $invitation = TenderInvitation::create([
            'tender_id'      => $tender->id,
            'invitee_name'   => 'ABC Contractors',
            'invitee_email'  => 'info@abc.qa',
            'status'         => 'PENDING',
            'tenant_slug'    => 'test-tenant',
        ]);

        $this->assertNotNull($invitation->id);
        $this->assertSame($tender->id, $invitation->tender_id);
    }

    /** @test */
    public function boq_with_sections_and_items_can_be_persisted(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $boq = BOQ::create([
            'type'        => 'tender_boq',
            'tender_id'   => $tender->id,
            'title'       => 'Main BOQ',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $section = BOQSection::create([
            'boq_id'      => $boq->id,
            'title'       => 'Civil Works',
            'sort_order'  => 1,
            'tenant_slug' => 'test-tenant',
        ]);

        $item = BOQItem::create([
            'boq_section_id' => $section->id,
            'description'    => 'Excavation works',
            'unit'           => 'm3',
            'quantity'       => 100.0,
            'unit_rate'      => 25.50,
            'amount'         => 2550.00,
            'tenant_slug'    => 'test-tenant',
        ]);

        $this->assertNotNull($boq->id);
        $this->assertNotNull($section->id);
        $this->assertNotNull($item->id);
        $this->assertSame('tender_boq', $boq->type);
        $this->assertSame('Civil Works', $section->title);
    }

    /** @test */
    public function contract_can_be_persisted_with_nullable_project_id(): void
    {
        $contract = Contract::create([
            'contract_number'            => 'CON-2026-001',
            'client_id'                  => '00000000-0000-0000-0000-000000000001',
            'project_id'                 => null,
            'contract_value'             => 500000.00,
            'approved_variations_value'  => 0,
            'revised_contract_value'     => 500000.00,
            'retention_percentage'       => 5.00,
            'status'                     => 'DRAFT',
            'tenant_slug'                => 'test-tenant',
        ]);

        $this->assertNotNull($contract->id);
        $this->assertNull($contract->project_id);
        $this->assertSame('CON-2026-001', $contract->contract_number);
    }

    /** @test */
    public function contract_variation_boq_changes_persists_as_json(): void
    {
        $contract = Contract::create([
            'contract_number'            => 'CON-2026-002',
            'contract_value'             => 500000.00,
            'approved_variations_value'  => 0,
            'revised_contract_value'     => 500000.00,
            'retention_percentage'       => 5.00,
            'status'                     => 'ACTIVE',
            'tenant_slug'                => 'test-tenant',
        ]);

        $boqChanges = [
            ['item' => 'Additional excavation', 'quantity' => 50, 'unit_rate' => 25.50, 'amount' => 1275.00],
        ];

        $variation = ContractVariation::create([
            'contract_id'      => $contract->id,
            'variation_number' => 'VO-001',
            'reason'           => 'Scope change — additional excavation required',
            'boq_changes'      => $boqChanges,
            'value_change'     => 1275.00,
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->assertNotNull($variation->id);
        $this->assertIsArray($variation->boq_changes);
        $this->assertCount(1, $variation->boq_changes);
        $this->assertSame('Additional excavation', $variation->boq_changes[0]['item']);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tender_has_many_invitations(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'PUBLISHED',
            'tenant_slug' => 'test-tenant',
        ]);

        TenderInvitation::create([
            'tender_id'   => $tender->id,
            'invitee_name' => 'Contractor A',
            'status'      => 'PENDING',
            'tenant_slug' => 'test-tenant',
        ]);

        TenderInvitation::create([
            'tender_id'   => $tender->id,
            'invitee_name' => 'Contractor B',
            'status'      => 'PENDING',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertCount(2, $tender->invitations);
    }

    /** @test */
    public function contract_has_many_variations(): void
    {
        $contract = Contract::create([
            'contract_number'            => 'CON-2026-003',
            'contract_value'             => 1000000.00,
            'approved_variations_value'  => 0,
            'revised_contract_value'     => 1000000.00,
            'retention_percentage'       => 5.00,
            'status'                     => 'ACTIVE',
            'tenant_slug'                => 'test-tenant',
        ]);

        ContractVariation::create([
            'contract_id'      => $contract->id,
            'variation_number' => 'VO-001',
            'value_change'     => 50000.00,
            'status'           => 'DRAFT',
            'tenant_slug'      => 'test-tenant',
        ]);

        $this->assertCount(1, $contract->variations);
    }

    /** @test */
    public function boq_section_belongs_to_boq(): void
    {
        $tender = Tender::create([
            'title'       => 'Test Tender',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $boq = BOQ::create([
            'type'        => 'tender_boq',
            'tender_id'   => $tender->id,
            'title'       => 'Main BOQ',
            'status'      => 'DRAFT',
            'tenant_slug' => 'test-tenant',
        ]);

        $section = BOQSection::create([
            'boq_id'      => $boq->id,
            'title'       => 'MEP Works',
            'sort_order'  => 2,
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertSame($boq->id, $section->boq->id);
    }
}
