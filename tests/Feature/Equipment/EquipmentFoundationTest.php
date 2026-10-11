<?php

declare(strict_types=1);

namespace Tests\Feature\Equipment;

use App\Equipment\Asset;
use App\Equipment\EquipmentAllocation;
use App\Equipment\EquipmentCostAllocation;
use App\Equipment\EquipmentService;
use App\Equipment\Events\AssetRegistered;
use App\Equipment\Events\AssetAllocated;
use App\Equipment\Events\AssetReleased;
use App\Equipment\Events\AssetDecommissioned;
use App\Equipment\Events\DepreciationRecorded;
use App\Equipment\Events\MaintenanceCompleted;
use App\Equipment\MaintenanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Equipment Domain/Data Foundation — TASK-011A.
 *
 * Verifies:
 *  1. All 4 Equipment tables exist in tenant schema
 *  2. No tenant_id on any table (Schema-per-Tenant — OD-001)
 *  3. tenant_slug present on all tables (cross-schema traceability — OD-001)
 *  4. Equipment migrations are in the correct path
 *  5. UUID PK — each model has uuid primary key
 *  6. Model connection — all models use 'tenant' connection
 *  7. Asset persistence — create and find
 *  8. Asset defaults — status='active', asset_type='owned', currency='QAR'
 *  9. Asset status helpers — isActive(), isUnderMaintenance(), isDecommissioned(), isAvailable()
 * 10. Duplicate asset_code rejected
 * 11. Allocation to project
 * 12. Release from project
 * 13. Cannot allocate already-allocated asset
 * 14. Cannot decommission allocated asset
 * 15. Maintenance flow (send → complete → back to active)
 * 16. Cost allocation recording
 * 17. Depreciation recording fires event
 * 18. Relationships — asset hasMany allocations, maintenanceRecords, costAllocations
 * 19. Tenant isolation (tenant_slug isolation)
 * 20. No Equipment Rental tables
 */
class EquipmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private EquipmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EquipmentService::class);
    }

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function assets_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('assets'),
            'assets table must exist in the tenant schema'
        );
    }

    /** @test */
    public function equipment_allocations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('equipment_allocations'),
            'equipment_allocations table must exist in the tenant schema'
        );
    }

    /** @test */
    public function maintenance_records_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('maintenance_records'),
            'maintenance_records table must exist in the tenant schema'
        );
    }

    /** @test */
    public function equipment_cost_allocations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('equipment_cost_allocations'),
            'equipment_cost_allocations table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation — No tenant_id Tests (OD-001)
    // -------------------------------------------------------------------------

    /** @test */
    public function assets_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('assets', 'tenant_id'),
            'assets must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function equipment_allocations_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('equipment_allocations', 'tenant_id'),
            'equipment_allocations must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function maintenance_records_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('maintenance_records', 'tenant_id'),
            'maintenance_records must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function equipment_cost_allocations_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('equipment_cost_allocations', 'tenant_id'),
            'equipment_cost_allocations must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Slug Tests (OD-001 cross-schema traceability)
    // -------------------------------------------------------------------------

    /** @test */
    public function assets_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('assets', 'tenant_slug'),
            'assets table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function equipment_allocations_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('equipment_allocations', 'tenant_slug'),
            'equipment_allocations table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function maintenance_records_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('maintenance_records', 'tenant_slug'),
            'maintenance_records table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function equipment_cost_allocations_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('equipment_cost_allocations', 'tenant_slug'),
            'equipment_cost_allocations table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function equipment_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/equipment/2026_10_02_110001_create_assets_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/equipment/2026_10_02_110002_create_equipment_allocations_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/equipment/2026_10_02_110003_create_maintenance_records_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/equipment/2026_10_02_110004_create_equipment_cost_allocations_table.php')
        );
    }

    /** @test */
    public function equipment_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_10_02_110001_create_assets_table.php'),
            'Equipment migrations must NOT be in the shared migration path'
        );
    }

    // -------------------------------------------------------------------------
    // Model Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function asset_model_uses_tenant_connection(): void
    {
        $asset = new Asset();
        $this->assertSame('tenant', $asset->getConnectionName());
    }

    /** @test */
    public function equipment_allocation_model_uses_tenant_connection(): void
    {
        $allocation = new EquipmentAllocation();
        $this->assertSame('tenant', $allocation->getConnectionName());
    }

    /** @test */
    public function maintenance_record_model_uses_tenant_connection(): void
    {
        $record = new MaintenanceRecord();
        $this->assertSame('tenant', $record->getConnectionName());
    }

    /** @test */
    public function equipment_cost_allocation_model_uses_tenant_connection(): void
    {
        $allocation = new EquipmentCostAllocation();
        $this->assertSame('tenant', $allocation->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // Asset Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function asset_can_be_persisted(): void
    {
        $asset = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-001',
            'name'        => 'Excavator',
        ]);

        $this->assertNotNull($asset->id);
        $this->assertSame('EQ-001', $asset->asset_code);
        $this->assertSame('Excavator', $asset->name);
        $this->assertSame('test-tenant', $asset->tenant_slug);
    }

    /** @test */
    public function asset_has_correct_defaults(): void
    {
        $asset = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-002',
            'name'        => 'Crane',
        ]);

        $this->assertSame('active', $asset->status);
        $this->assertSame('owned', $asset->asset_type);
        $this->assertSame('QAR', $asset->currency);
    }

    /** @test */
    public function asset_uuid_is_generated(): void
    {
        $asset = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-003',
            'name'        => 'Bulldozer',
        ]);

        $this->assertNotNull($asset->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $asset->id,
            'Asset ID must be a valid UUID'
        );
    }

    /** @test */
    public function asset_status_helpers_work_correctly(): void
    {
        $active = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-010',
            'name'        => 'Active Asset',
            'status'      => 'active',
        ]);

        $maintenance = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-011',
            'name'        => 'Maintenance Asset',
            'status'      => 'under_maintenance',
        ]);

        $decommissioned = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-012',
            'name'        => 'Decommissioned Asset',
            'status'      => 'decommissioned',
        ]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isUnderMaintenance());
        $this->assertFalse($active->isDecommissioned());
        $this->assertTrue($active->isAvailable()); // active + no project_id

        $this->assertFalse($maintenance->isActive());
        $this->assertTrue($maintenance->isUnderMaintenance());

        $this->assertFalse($decommissioned->isActive());
        $this->assertTrue($decommissioned->isDecommissioned());
    }

    /** @test */
    public function asset_code_must_be_unique(): void
    {
        Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-UNIQUE',
            'name'        => 'First Asset',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-UNIQUE',
            'name'        => 'Second Asset',
        ]);
    }

    // -------------------------------------------------------------------------
    // Service Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function register_asset_creates_asset_and_dispatches_event(): void
    {
        Event::fake();

        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-SVC-001',
            name:       'Service Test Asset',
            category:   'heavy_machinery',
        );

        $this->assertNotNull($asset->id);
        $this->assertSame('EQ-SVC-001', $asset->asset_code);
        $this->assertSame('active', $asset->status);
        $this->assertSame('test-tenant', $asset->tenant_slug);

        Event::assertDispatched(AssetRegistered::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && $event->assetCode === 'EQ-SVC-001'
                && $event->tenantSlug === 'test-tenant';
        });
    }

    /** @test */
    public function register_asset_rejects_duplicate_asset_code(): void
    {
        $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-DUP',
            name:       'First Asset',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-DUP',
            name:       'Second Asset',
        );
    }

    /** @test */
    public function allocate_to_project_creates_allocation_and_dispatches_event(): void
    {
        Event::fake();

        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-ALLOC-001',
            name:       'Allocation Test Asset',
        );

        $projectId  = '00000000-0000-0000-0000-000000000001';
        $allocation = $this->service->allocateToProject(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
            projectId:  $projectId,
        );

        $this->assertNotNull($allocation->id);
        $this->assertSame($asset->id, $allocation->asset_id);
        $this->assertSame($projectId, $allocation->project_id);

        $asset->refresh();
        $this->assertSame($projectId, $asset->project_id);
        $this->assertFalse($asset->isAvailable()); // allocated

        Event::assertDispatched(AssetAllocated::class);
    }

    /** @test */
    public function cannot_allocate_already_allocated_asset(): void
    {
        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-ALLOC-002',
            name:       'Already Allocated Asset',
        );

        $this->service->allocateToProject(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
            projectId:  '00000000-0000-0000-0000-000000000001',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->allocateToProject(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
            projectId:  '00000000-0000-0000-0000-000000000002',
        );
    }

    /** @test */
    public function release_from_project_clears_allocation_and_dispatches_event(): void
    {
        Event::fake();

        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-RELEASE-001',
            name:       'Release Test Asset',
        );

        $this->service->allocateToProject(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
            projectId:  '00000000-0000-0000-0000-000000000001',
        );

        $released = $this->service->releaseFromProject(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
        );

        $this->assertNull($released->project_id);
        $this->assertNull($released->assigned_at);
        $this->assertTrue($released->isAvailable());

        Event::assertDispatched(AssetReleased::class);
    }

    /** @test */
    public function cannot_decommission_allocated_asset(): void
    {
        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-DECOMM-001',
            name:       'Allocated Asset',
        );

        $this->service->allocateToProject(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
            projectId:  '00000000-0000-0000-0000-000000000001',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->decommissionAsset(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
        );
    }

    /** @test */
    public function maintenance_flow_send_complete_back_to_active(): void
    {
        Event::fake();

        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-MAINT-001',
            name:       'Maintenance Test Asset',
        );

        // Send to maintenance
        $record = $this->service->sendToMaintenance(
            tenantSlug:      'test-tenant',
            assetId:         $asset->id,
            maintenanceType: 'scheduled',
        );

        $this->assertNotNull($record->id);
        $this->assertSame('pending', $record->status);

        $asset->refresh();
        $this->assertTrue($asset->isUnderMaintenance());

        // Complete maintenance
        $completed = $this->service->completeMaintenance(
            tenantSlug:          'test-tenant',
            maintenanceRecordId: $record->id,
            cost:                500.00,
            performedBy:         'Technician A',
        );

        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);

        $asset->refresh();
        $this->assertTrue($asset->isActive());

        Event::assertDispatched(MaintenanceCompleted::class);
    }

    /** @test */
    public function cost_allocation_can_be_recorded(): void
    {
        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-COST-001',
            name:       'Cost Test Asset',
        );

        $allocation = $this->service->recordCostAllocation(
            tenantSlug:    'test-tenant',
            assetId:       $asset->id,
            projectId:     '00000000-0000-0000-0000-000000000001',
            periodStart:   '2026-10-01',
            periodEnd:     '2026-10-31',
            allocatedCost: 1500.00,
        );

        $this->assertNotNull($allocation->id);
        $this->assertSame($asset->id, $allocation->asset_id);
        $this->assertSame('QAR', $allocation->currency);
    }

    /** @test */
    public function depreciation_recording_fires_event(): void
    {
        Event::fake();

        $asset = $this->service->registerAsset(
            tenantSlug:      'test-tenant',
            assetCode:       'EQ-DEPR-001',
            name:            'Depreciation Test Asset',
            acquisitionCost: 10000.00,
        );

        $updated = $this->service->recordDepreciation(
            tenantSlug:          'test-tenant',
            assetId:             $asset->id,
            depreciationAmount:  1000.00,
        );

        $this->assertSame('9000.0000', $updated->current_value);

        Event::assertDispatched(DepreciationRecorded::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && $event->depreciationAmount === 1000.00
                && $event->tenantSlug === 'test-tenant';
        });
    }

    /** @test */
    public function decommission_asset_dispatches_event(): void
    {
        Event::fake();

        $asset = $this->service->registerAsset(
            tenantSlug: 'test-tenant',
            assetCode:  'EQ-DECOMM-002',
            name:       'Decommission Test Asset',
        );

        $decommissioned = $this->service->decommissionAsset(
            tenantSlug: 'test-tenant',
            assetId:    $asset->id,
        );

        $this->assertTrue($decommissioned->isDecommissioned());

        Event::assertDispatched(AssetDecommissioned::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && $event->tenantSlug === 'test-tenant';
        });
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function asset_has_many_allocations(): void
    {
        $asset = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-REL-001',
            'name'        => 'Relationship Test Asset',
        ]);

        EquipmentAllocation::create([
            'tenant_slug'  => 'test-tenant',
            'asset_id'     => $asset->id,
            'project_id'   => '00000000-0000-0000-0000-000000000001',
            'allocated_at' => now(),
        ]);

        $this->assertCount(1, $asset->allocations);
    }

    /** @test */
    public function asset_has_many_maintenance_records(): void
    {
        $asset = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-REL-002',
            'name'        => 'Maintenance Relationship Asset',
        ]);

        MaintenanceRecord::create([
            'tenant_slug'      => 'test-tenant',
            'asset_id'         => $asset->id,
            'maintenance_type' => 'scheduled',
        ]);

        MaintenanceRecord::create([
            'tenant_slug'      => 'test-tenant',
            'asset_id'         => $asset->id,
            'maintenance_type' => 'corrective',
        ]);

        $this->assertCount(2, $asset->maintenanceRecords);
    }

    /** @test */
    public function asset_has_many_cost_allocations(): void
    {
        $asset = Asset::create([
            'tenant_slug' => 'test-tenant',
            'asset_code'  => 'EQ-REL-003',
            'name'        => 'Cost Allocation Relationship Asset',
        ]);

        EquipmentCostAllocation::create([
            'tenant_slug'    => 'test-tenant',
            'asset_id'       => $asset->id,
            'project_id'     => '00000000-0000-0000-0000-000000000001',
            'period_start'   => '2026-10-01',
            'period_end'     => '2026-10-31',
            'allocated_cost' => 1000.00,
        ]);

        $this->assertCount(1, $asset->costAllocations);
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function assets_are_isolated_within_tenant_schema(): void
    {
        $asset1 = $this->service->registerAsset(
            tenantSlug: 'tenant-a',
            assetCode:  'EQ-ISO-001',
            name:       'Tenant A Asset',
        );

        $asset2 = $this->service->registerAsset(
            tenantSlug: 'tenant-b',
            assetCode:  'EQ-ISO-002',
            name:       'Tenant B Asset',
        );

        $this->assertSame('tenant-a', $asset1->tenant_slug);
        $this->assertSame('tenant-b', $asset2->tenant_slug);

        $listA = $this->service->listAssets('tenant-a');
        $listB = $this->service->listAssets('tenant-b');

        $this->assertCount(1, $listA);
        $this->assertCount(1, $listB);
        $this->assertSame('EQ-ISO-001', $listA->first()->asset_code);
        $this->assertSame('EQ-ISO-002', $listB->first()->asset_code);
    }

    // -------------------------------------------------------------------------
    // Scope Verification — No Equipment Rental Tables
    // -------------------------------------------------------------------------

    /** @test */
    public function equipment_rentals_table_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasTable('equipment_rentals'),
            'equipment_rentals table must NOT exist — Equipment Rental is out of scope (OD-012 IMPL-DEFERRABLE — V2/Future)'
        );
    }

    /** @test */
    public function equipment_service_resolves_from_container(): void
    {
        $service = app(EquipmentService::class);
        $this->assertInstanceOf(EquipmentService::class, $service);
    }
}
