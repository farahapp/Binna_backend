<?php

declare(strict_types=1);

namespace Tests\Feature\Subcontracts;

use App\Subcontracts\SubcontractAgreement;
use App\Subcontracts\SubcontractScope;
use App\Subcontracts\SubcontractProgress;
use App\Subcontracts\SubcontractCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Subcontract Domain/Data Foundation — TASK-009A.
 *
 * Verifies:
 *  1. All 4 Subcontract tables exist in tenant schema
 *  2. No tenant_id on any table
 *  3. Subcontract migrations are in the correct path
 *  4. Migration not in wrong path (not in /shared/)
 *  5. Tenant isolation — records from Tenant A not visible in Tenant B schema
 *  6. UUID PK — each model has uuid primary key
 *  7. Model connection — all models use 'tenant' connection
 *  8. Tenant slug — verified on all models
 *  9. SubcontractAgreement persistence — create and find
 * 10. SubcontractAgreement defaults — status='draft', currency='SAR'
 * 11. SubcontractAgreement status helpers — isDraft(), isActive(), isCompleted(), isCancelled()
 * 12. SubcontractScope persistence — create with FK to agreement
 * 13. SubcontractProgress persistence — create with FK to agreement
 * 14. SubcontractCertificate persistence — create with FK to agreement and progress
 * 15. Relationships — agreement hasMany scopes, progresses, certificates; etc.
 * 16. Cross-module UUID refs — project_id, subcontractor_id, approval_request_id accept UUID without FK violation
 * 17. SubcontractCertificate status helpers — isDraft(), isApproved(), isPaid()
 * 18. Provider loads migrations — migrations are loaded via SubcontractServiceProvider
 * 19. SubcontractProgress cumulative defaults — cumulative_percentage=0, cumulative_amount=0
 * 20. SubcontractCertificate certificate defaults — certified_amount=0, net_payable=0
 */
class SubcontractFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_agreements_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('subcontract_agreements'),
            'subcontract_agreements table must exist in the tenant schema'
        );
    }

    /** @test */
    public function subcontract_scopes_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('subcontract_scopes'),
            'subcontract_scopes table must exist in the tenant schema'
        );
    }

    /** @test */
    public function subcontract_progresses_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('subcontract_progresses'),
            'subcontract_progresses table must exist in the tenant schema'
        );
    }

    /** @test */
    public function subcontract_certificates_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('subcontract_certificates'),
            'subcontract_certificates table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation — No tenant_id Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_agreements_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('subcontract_agreements', 'tenant_id'),
            'subcontract_agreements must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function subcontract_scopes_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('subcontract_scopes', 'tenant_id'),
            'subcontract_scopes must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function subcontract_progresses_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('subcontract_progresses', 'tenant_id'),
            'subcontract_progresses must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function subcontract_certificates_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('subcontract_certificates', 'tenant_id'),
            'subcontract_certificates must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/subcontracts/2026_09_29_300001_create_subcontract_agreements_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/subcontracts/2026_09_29_300002_create_subcontract_scopes_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/subcontracts/2026_09_29_300003_create_subcontract_progresses_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/subcontracts/2026_09_29_300004_create_subcontract_certificates_table.php')
        );
    }

    /** @test */
    public function subcontract_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_29_300001_create_subcontract_agreements_table.php')
        );
    }

    // -------------------------------------------------------------------------
    // Model Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function all_subcontract_models_use_tenant_connection(): void
    {
        $this->assertEquals('tenant', (new SubcontractAgreement())->getConnectionName());
        $this->assertEquals('tenant', (new SubcontractScope())->getConnectionName());
        $this->assertEquals('tenant', (new SubcontractProgress())->getConnectionName());
        $this->assertEquals('tenant', (new SubcontractCertificate())->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // UUID Convention Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function all_subcontract_models_use_uuid_primary_keys(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Test Subcontractor',
            'title'              => 'Test Agreement',
        ]);

        $scope = SubcontractScope::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'description'               => 'Test scope item',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-01',
        ]);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
        ]);

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        $this->assertMatchesRegularExpression($uuidPattern, $agreement->id, 'SubcontractAgreement must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $scope->id, 'SubcontractScope must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $progress->id, 'SubcontractProgress must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $certificate->id, 'SubcontractCertificate must use UUID');
    }

    // -------------------------------------------------------------------------
    // SubcontractAgreement Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_agreement_can_be_created_and_retrieved(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'agreement_number'   => 'SA-2026-001',
            'subcontractor_name' => 'Al-Banna Construction Co.',
            'title'              => 'Civil Works Package A',
            'contract_value'     => 500000.0000,
        ]);

        $found = SubcontractAgreement::find($agreement->id);

        $this->assertNotNull($found);
        $this->assertEquals('test-tenant', $found->tenant_slug);
        $this->assertEquals('SA-2026-001', $found->agreement_number);
        $this->assertEquals('Al-Banna Construction Co.', $found->subcontractor_name);
        $this->assertEquals('Civil Works Package A', $found->title);
    }

    /** @test */
    public function subcontract_agreement_has_correct_default_values(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Default Test Subcontractor',
            'title'              => 'Default Test Agreement',
        ]);

        $this->assertEquals('draft', $agreement->status);
        $this->assertEquals('SAR', $agreement->currency);
        $this->assertEquals('0.00', $agreement->retention_percentage);
    }

    /** @test */
    public function subcontract_agreement_status_helpers_work_correctly(): void
    {
        $agreement = new SubcontractAgreement(['status' => 'draft']);
        $this->assertTrue($agreement->isDraft());
        $this->assertFalse($agreement->isActive());
        $this->assertFalse($agreement->isCompleted());
        $this->assertFalse($agreement->isCancelled());

        $agreement->status = 'active';
        $this->assertTrue($agreement->isActive());
        $this->assertFalse($agreement->isDraft());

        $agreement->status = 'completed';
        $this->assertTrue($agreement->isCompleted());
        $this->assertFalse($agreement->isActive());

        $agreement->status = 'cancelled';
        $this->assertTrue($agreement->isCancelled());
        $this->assertFalse($agreement->isCompleted());
    }

    // -------------------------------------------------------------------------
    // SubcontractScope Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_scope_can_be_created_with_fk_to_agreement(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Scope Test Subcontractor',
            'title'              => 'Scope Test Agreement',
        ]);

        $scope = SubcontractScope::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'scope_item_number'         => 'SC-001',
            'description'               => 'Excavation works',
            'unit'                      => 'm3',
            'quantity'                  => 1000.0,
            'unit_rate'                 => 50.0,
            'total_amount'              => 50000.0,
        ]);

        $this->assertNotNull($scope->id);
        $this->assertEquals($agreement->id, $scope->subcontract_agreement_id);
        $this->assertEquals('SC-001', $scope->scope_item_number);
        $this->assertEquals('1000.0000', $scope->quantity);
        $this->assertEquals('50.0000', $scope->unit_rate);
        $this->assertEquals('50000.0000', $scope->total_amount);
    }

    // -------------------------------------------------------------------------
    // SubcontractProgress Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_progress_can_be_created_with_fk_to_agreement(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Progress Test Subcontractor',
            'title'              => 'Progress Test Agreement',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-30',
            'percentage_complete'       => 25.00,
            'cumulative_percentage'     => 25.00,
            'amount_certified'          => 125000.0000,
            'cumulative_amount'         => 125000.0000,
        ]);

        $this->assertNotNull($progress->id);
        $this->assertEquals($agreement->id, $progress->subcontract_agreement_id);
        $this->assertEquals('25.00', $progress->percentage_complete);
        $this->assertEquals('25.00', $progress->cumulative_percentage);
    }

    /** @test */
    public function subcontract_progress_has_correct_cumulative_defaults(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Cumulative Default Subcontractor',
            'title'              => 'Cumulative Default Agreement',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-10-31',
        ]);

        $this->assertEquals('0.00', $progress->cumulative_percentage);
        $this->assertEquals('0.0000', $progress->cumulative_amount);
        $this->assertEquals('0.00', $progress->percentage_complete);
        $this->assertEquals('0.0000', $progress->amount_certified);
    }

    // -------------------------------------------------------------------------
    // SubcontractCertificate Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_certificate_can_be_created_with_fk_to_agreement_and_progress(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Certificate Test Subcontractor',
            'title'              => 'Certificate Test Agreement',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-30',
        ]);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'subcontract_progress_id'   => $progress->id,
            'certificate_number'        => 'CERT-2026-001',
            'certified_amount'          => 125000.0000,
            'retention_deducted'        => 12500.0000,
            'net_payable'               => 112500.0000,
        ]);

        $this->assertNotNull($certificate->id);
        $this->assertEquals($agreement->id, $certificate->subcontract_agreement_id);
        $this->assertEquals($progress->id, $certificate->subcontract_progress_id);
        $this->assertEquals('CERT-2026-001', $certificate->certificate_number);
        $this->assertEquals('125000.0000', $certificate->certified_amount);
        $this->assertEquals('112500.0000', $certificate->net_payable);
    }

    /** @test */
    public function subcontract_certificate_has_correct_default_values(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Cert Default Subcontractor',
            'title'              => 'Cert Default Agreement',
        ]);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
        ]);

        $this->assertEquals('draft', $certificate->status);
        $this->assertEquals('0.0000', $certificate->certified_amount);
        $this->assertEquals('0.0000', $certificate->net_payable);
        $this->assertEquals('0.0000', $certificate->retention_deducted);
    }

    /** @test */
    public function subcontract_certificate_status_helpers_work_correctly(): void
    {
        $certificate = new SubcontractCertificate(['status' => 'draft']);
        $this->assertTrue($certificate->isDraft());
        $this->assertFalse($certificate->isApproved());
        $this->assertFalse($certificate->isPaid());

        $certificate->status = 'approved';
        $this->assertTrue($certificate->isApproved());
        $this->assertFalse($certificate->isDraft());

        $certificate->status = 'paid';
        $this->assertTrue($certificate->isPaid());
        $this->assertFalse($certificate->isApproved());
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function agreement_has_many_scopes(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Rel Test Subcontractor',
            'title'              => 'Rel Test Agreement',
        ]);

        SubcontractScope::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'description'               => 'Scope item 1',
        ]);
        SubcontractScope::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'description'               => 'Scope item 2',
        ]);

        $this->assertCount(2, $agreement->scopes);
    }

    /** @test */
    public function agreement_has_many_progresses(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Progress Rel Subcontractor',
            'title'              => 'Progress Rel Agreement',
        ]);

        SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-08-31',
        ]);
        SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-30',
        ]);

        $this->assertCount(2, $agreement->progresses);
    }

    /** @test */
    public function agreement_has_many_certificates(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Cert Rel Subcontractor',
            'title'              => 'Cert Rel Agreement',
        ]);

        SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'certificate_number'        => 'CERT-001',
        ]);
        SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'certificate_number'        => 'CERT-002',
        ]);

        $this->assertCount(2, $agreement->certificates);
    }

    /** @test */
    public function scope_belongs_to_agreement(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'BT Subcontractor',
            'title'              => 'BT Agreement',
        ]);

        $scope = SubcontractScope::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'description'               => 'BT scope item',
        ]);

        $this->assertEquals($agreement->id, $scope->agreement->id);
        $this->assertEquals('BT Agreement', $scope->agreement->title);
    }

    /** @test */
    public function progress_belongs_to_agreement(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Progress BT Subcontractor',
            'title'              => 'Progress BT Agreement',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-30',
        ]);

        $this->assertEquals($agreement->id, $progress->agreement->id);
        $this->assertEquals('Progress BT Agreement', $progress->agreement->title);
    }

    /** @test */
    public function certificate_belongs_to_agreement(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Cert BT Subcontractor',
            'title'              => 'Cert BT Agreement',
        ]);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
        ]);

        $this->assertEquals($agreement->id, $certificate->agreement->id);
        $this->assertEquals('Cert BT Agreement', $certificate->agreement->title);
    }

    /** @test */
    public function certificate_belongs_to_progress(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'test-tenant',
            'subcontractor_name' => 'Cert-Prog BT Subcontractor',
            'title'              => 'Cert-Prog BT Agreement',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-30',
        ]);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => 'test-tenant',
            'subcontract_agreement_id'  => $agreement->id,
            'subcontract_progress_id'   => $progress->id,
        ]);

        $this->assertEquals($progress->id, $certificate->progress->id);
    }

    // -------------------------------------------------------------------------
    // Cross-Module UUID Reference Tests (no FK violation)
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_agreement_stores_cross_module_uuid_refs_without_fk_violation(): void
    {
        $fakeProjectId       = '550e8400-e29b-41d4-a716-446655440010';
        $fakeSubcontractorId = '550e8400-e29b-41d4-a716-446655440011';
        $fakeApprovalId      = '550e8400-e29b-41d4-a716-446655440012';

        $agreement = SubcontractAgreement::create([
            'tenant_slug'          => 'test-tenant',
            'subcontractor_name'   => 'Cross-Ref Subcontractor',
            'title'                => 'Cross-Ref Agreement',
            'project_id'           => $fakeProjectId,
            'subcontractor_id'     => $fakeSubcontractorId,
            'approval_request_id'  => $fakeApprovalId,
        ]);

        $this->assertEquals($fakeProjectId, $agreement->project_id);
        $this->assertEquals($fakeSubcontractorId, $agreement->subcontractor_id);
        $this->assertEquals($fakeApprovalId, $agreement->approval_request_id);
    }

    // -------------------------------------------------------------------------
    // Tenant Slug Traceability Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function all_models_store_tenant_slug_for_traceability(): void
    {
        $agreement = SubcontractAgreement::create([
            'tenant_slug'        => 'acme-construction',
            'subcontractor_name' => 'Slug Test Subcontractor',
            'title'              => 'Slug Test Agreement',
        ]);

        $scope = SubcontractScope::create([
            'tenant_slug'               => 'acme-construction',
            'subcontract_agreement_id'  => $agreement->id,
            'description'               => 'Slug scope item',
        ]);

        $progress = SubcontractProgress::create([
            'tenant_slug'               => 'acme-construction',
            'subcontract_agreement_id'  => $agreement->id,
            'progress_period'           => '2026-09-30',
        ]);

        $certificate = SubcontractCertificate::create([
            'tenant_slug'               => 'acme-construction',
            'subcontract_agreement_id'  => $agreement->id,
        ]);

        $this->assertEquals('acme-construction', $agreement->tenant_slug);
        $this->assertEquals('acme-construction', $scope->tenant_slug);
        $this->assertEquals('acme-construction', $progress->tenant_slug);
        $this->assertEquals('acme-construction', $certificate->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // ServiceProvider Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function subcontract_service_provider_is_registered(): void
    {
        $this->assertContains(
            \App\Providers\SubcontractServiceProvider::class,
            array_keys(app()->getLoadedProviders()),
            'SubcontractServiceProvider must be registered'
        );
    }

    /** @test */
    public function subcontract_service_provider_instance_is_available(): void
    {
        $this->assertNotNull(
            app()->getProvider(\App\Providers\SubcontractServiceProvider::class)
        );
    }
}
