<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Procurement\POItem;
use App\Procurement\PurchaseOrder;
use App\Procurement\PurchaseRequest;
use App\Procurement\RFQ;
use App\Procurement\SupplierQuotation;
use App\Procurement\TenantSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Procurement Domain/Data Foundation — TASK-007A.
 *
 * Verifies:
 *  1. All 6 Procurement tables exist in tenant schema
 *  2. Procurement migrations are in the correct path
 *  3. Model persistence works for all 6 entities
 *  4. Relationships work correctly
 *  5. Tenant isolation — no tenant_id column
 *  6. ProcurementServiceProvider loads correctly
 *  7. UUID conventions verified
 */
class ProcurementFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_suppliers_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('tenant_suppliers'),
            'tenant_suppliers table must exist in the tenant schema'
        );
    }

    /** @test */
    public function purchase_requests_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('purchase_requests'),
            'purchase_requests table must exist in the tenant schema'
        );
    }

    /** @test */
    public function rfqs_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('rfqs'),
            'rfqs table must exist in the tenant schema'
        );
    }

    /** @test */
    public function supplier_quotations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('supplier_quotations'),
            'supplier_quotations table must exist in the tenant schema'
        );
    }

    /** @test */
    public function purchase_orders_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('purchase_orders'),
            'purchase_orders table must exist in the tenant schema'
        );
    }

    /** @test */
    public function po_items_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('po_items'),
            'po_items table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function procurement_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/procurement/2026_09_29_100001_create_tenant_suppliers_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/procurement/2026_09_29_100002_create_purchase_requests_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/procurement/2026_09_29_100003_create_rfqs_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/procurement/2026_09_29_100004_create_supplier_quotations_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/procurement/2026_09_29_100005_create_purchase_orders_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/procurement/2026_09_29_100006_create_po_items_table.php')
        );
    }

    /** @test */
    public function procurement_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_29_100001_create_tenant_suppliers_table.php')
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_suppliers_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('tenant_suppliers', 'tenant_id'),
            'tenant_suppliers must NOT have a tenant_id column — isolation is physical'
        );
    }

    /** @test */
    public function purchase_requests_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('purchase_requests', 'tenant_id'),
            'purchase_requests must NOT have a tenant_id column — isolation is physical'
        );
    }

    /** @test */
    public function rfqs_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('rfqs', 'tenant_id'),
            'rfqs must NOT have a tenant_id column — isolation is physical'
        );
    }

    /** @test */
    public function supplier_quotations_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('supplier_quotations', 'tenant_id'),
            'supplier_quotations must NOT have a tenant_id column — isolation is physical'
        );
    }

    /** @test */
    public function purchase_orders_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('purchase_orders', 'tenant_id'),
            'purchase_orders must NOT have a tenant_id column — isolation is physical'
        );
    }

    /** @test */
    public function po_items_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('po_items', 'tenant_id'),
            'po_items must NOT have a tenant_id column — isolation is physical'
        );
    }

    // -------------------------------------------------------------------------
    // Model Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_supplier_can_be_created_and_retrieved(): void
    {
        $supplier = TenantSupplier::create([
            'tenant_slug'   => 'test-tenant',
            'name'          => 'Test Supplier Co.',
            'code'          => 'SUP-001',
            'contact_email' => 'supplier@test.com',
            'is_active'     => true,
        ]);

        $this->assertNotNull($supplier->id);
        $this->assertTrue(strlen($supplier->id) === 36, 'ID must be a UUID');
        $this->assertEquals('test-tenant', $supplier->tenant_slug);
        $this->assertEquals('Test Supplier Co.', $supplier->name);
        $this->assertTrue($supplier->is_active);
    }

    /** @test */
    public function purchase_request_can_be_created_with_default_draft_status(): void
    {
        $pr = PurchaseRequest::create([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Office Supplies Q4',
            'description' => 'Quarterly office supplies purchase',
        ]);

        $this->assertNotNull($pr->id);
        $this->assertTrue(strlen($pr->id) === 36, 'ID must be a UUID');
        $this->assertEquals('draft', $pr->status);
        $this->assertTrue($pr->isDraft());
    }

    /** @test */
    public function rfq_can_be_created_and_linked_to_purchase_request(): void
    {
        $pr = PurchaseRequest::create([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Office Supplies Q4',
        ]);

        $rfq = RFQ::create([
            'tenant_slug'          => 'test-tenant',
            'purchase_request_id'  => $pr->id,
            'title'                => 'RFQ for Office Supplies',
            'status'               => 'draft',
        ]);

        $this->assertNotNull($rfq->id);
        $this->assertEquals('draft', $rfq->status);
        $this->assertEquals($pr->id, $rfq->purchase_request_id);
    }

    /** @test */
    public function supplier_quotation_can_be_created_and_linked(): void
    {
        $supplier = TenantSupplier::create([
            'tenant_slug' => 'test-tenant',
            'name'        => 'Supplier A',
        ]);

        $pr = PurchaseRequest::create([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $rfq = RFQ::create([
            'tenant_slug'         => 'test-tenant',
            'purchase_request_id' => $pr->id,
            'title'               => 'Test RFQ',
        ]);

        $quotation = SupplierQuotation::create([
            'tenant_slug'  => 'test-tenant',
            'rfq_id'       => $rfq->id,
            'supplier_id'  => $supplier->id,
            'total_amount' => 5000.00,
            'currency'     => 'SAR',
        ]);

        $this->assertNotNull($quotation->id);
        $this->assertEquals('submitted', $quotation->status);
        $this->assertEquals('5000.00', $quotation->total_amount);
    }

    /** @test */
    public function purchase_order_can_be_created_with_items(): void
    {
        $supplier = TenantSupplier::create([
            'tenant_slug' => 'test-tenant',
            'name'        => 'Supplier B',
        ]);

        $po = PurchaseOrder::create([
            'tenant_slug' => 'test-tenant',
            'supplier_id' => $supplier->id,
            'po_number'   => 'PO-2026-001',
            'total_amount' => 1500.00,
        ]);

        $item = POItem::create([
            'purchase_order_id' => $po->id,
            'description'       => 'Office Chair',
            'quantity'          => 5,
            'unit'              => 'pcs',
            'unit_price'        => 300.00,
            'total_price'       => 1500.00,
        ]);

        $this->assertNotNull($po->id);
        $this->assertEquals('draft', $po->status);
        $this->assertNotNull($item->id);
        $this->assertEquals($po->id, $item->purchase_order_id);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function purchase_request_has_many_rfqs(): void
    {
        $pr = PurchaseRequest::create([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        RFQ::create(['tenant_slug' => 'test-tenant', 'purchase_request_id' => $pr->id, 'title' => 'RFQ 1']);
        RFQ::create(['tenant_slug' => 'test-tenant', 'purchase_request_id' => $pr->id, 'title' => 'RFQ 2']);

        $this->assertCount(2, $pr->rfqs);
    }

    /** @test */
    public function rfq_belongs_to_purchase_request(): void
    {
        $pr = PurchaseRequest::create([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $rfq = RFQ::create([
            'tenant_slug'         => 'test-tenant',
            'purchase_request_id' => $pr->id,
            'title'               => 'Test RFQ',
        ]);

        $this->assertEquals($pr->id, $rfq->purchaseRequest->id);
    }

    /** @test */
    public function rfq_has_many_quotations(): void
    {
        $supplier1 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 1']);
        $supplier2 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 2']);

        $pr = PurchaseRequest::create(['tenant_slug' => 'test-tenant', 'title' => 'Test PR']);
        $rfq = RFQ::create(['tenant_slug' => 'test-tenant', 'purchase_request_id' => $pr->id, 'title' => 'Test RFQ']);

        SupplierQuotation::create(['tenant_slug' => 'test-tenant', 'rfq_id' => $rfq->id, 'supplier_id' => $supplier1->id, 'total_amount' => 1000]);
        SupplierQuotation::create(['tenant_slug' => 'test-tenant', 'rfq_id' => $rfq->id, 'supplier_id' => $supplier2->id, 'total_amount' => 1200]);

        $this->assertCount(2, $rfq->quotations);
    }

    /** @test */
    public function purchase_order_has_many_items(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = PurchaseOrder::create(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id, 'po_number' => 'PO-001', 'total_amount' => 0]);

        POItem::create(['purchase_order_id' => $po->id, 'description' => 'Item 1', 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100]);
        POItem::create(['purchase_order_id' => $po->id, 'description' => 'Item 2', 'quantity' => 2, 'unit_price' => 200, 'total_price' => 400]);

        $this->assertCount(2, $po->items);
    }

    /** @test */
    public function tenant_supplier_has_many_quotations(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $pr = PurchaseRequest::create(['tenant_slug' => 'test-tenant', 'title' => 'Test PR']);
        $rfq = RFQ::create(['tenant_slug' => 'test-tenant', 'purchase_request_id' => $pr->id, 'title' => 'Test RFQ']);

        SupplierQuotation::create(['tenant_slug' => 'test-tenant', 'rfq_id' => $rfq->id, 'supplier_id' => $supplier->id, 'total_amount' => 500]);

        $this->assertCount(1, $supplier->quotations);
    }

    /** @test */
    public function tenant_supplier_has_many_purchase_orders(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);

        PurchaseOrder::create(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id, 'po_number' => 'PO-001', 'total_amount' => 0]);
        PurchaseOrder::create(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id, 'po_number' => 'PO-002', 'total_amount' => 0]);

        $this->assertCount(2, $supplier->purchaseOrders);
    }

    // -------------------------------------------------------------------------
    // UUID Convention Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function all_procurement_models_use_uuid_primary_keys(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'UUID Test Supplier']);
        $pr = PurchaseRequest::create(['tenant_slug' => 'test-tenant', 'title' => 'UUID Test PR']);
        $rfq = RFQ::create(['tenant_slug' => 'test-tenant', 'purchase_request_id' => $pr->id, 'title' => 'UUID Test RFQ']);
        $quotation = SupplierQuotation::create(['tenant_slug' => 'test-tenant', 'rfq_id' => $rfq->id, 'supplier_id' => $supplier->id, 'total_amount' => 0]);
        $po = PurchaseOrder::create(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id, 'po_number' => 'PO-UUID', 'total_amount' => 0]);
        $item = POItem::create(['purchase_order_id' => $po->id, 'description' => 'UUID Item', 'quantity' => 1, 'unit_price' => 0, 'total_price' => 0]);

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        $this->assertMatchesRegularExpression($uuidPattern, $supplier->id, 'TenantSupplier must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $pr->id, 'PurchaseRequest must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $rfq->id, 'RFQ must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $quotation->id, 'SupplierQuotation must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $po->id, 'PurchaseOrder must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $item->id, 'POItem must use UUID');
    }

    // -------------------------------------------------------------------------
    // ServiceProvider Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function procurement_service_provider_is_registered(): void
    {
        $this->assertContains(
            \App\Providers\ProcurementServiceProvider::class,
            array_keys(app()->getLoadedProviders()),
            'ProcurementServiceProvider must be registered'
        );
    }

    /** @test */
    public function procurement_service_provider_instance_is_available(): void
    {
        $this->assertNotNull(
            app()->getProvider(\App\Providers\ProcurementServiceProvider::class)
        );
    }

    // -------------------------------------------------------------------------
    // Status Helper Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function purchase_request_status_helpers_work_correctly(): void
    {
        $pr = new PurchaseRequest(['status' => 'draft']);
        $this->assertTrue($pr->isDraft());
        $this->assertFalse($pr->isApproved());

        $pr->status = 'approved';
        $this->assertTrue($pr->isApproved());
        $this->assertFalse($pr->isDraft());
    }

    /** @test */
    public function purchase_order_status_helpers_work_correctly(): void
    {
        $po = new PurchaseOrder(['status' => 'draft']);
        $this->assertTrue($po->isDraft());
        $this->assertFalse($po->isIssued());

        $po->status = 'issued';
        $this->assertTrue($po->isIssued());
        $this->assertFalse($po->isDraft());
    }

    /** @test */
    public function tenant_supplier_is_active_by_default(): void
    {
        $supplier = TenantSupplier::create([
            'tenant_slug' => 'test-tenant',
            'name'        => 'Active Supplier',
        ]);

        $this->assertTrue($supplier->isActive());
    }
}
