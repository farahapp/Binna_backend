<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Inventory\GoodsReceiptNote;
use App\Inventory\GRNItem;
use App\Inventory\InventoryItem;
use App\Inventory\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Inventory Domain/Data Foundation — TASK-008A.
 *
 * Verifies:
 *  1. All 4 Inventory tables exist in tenant schema
 *  2. Inventory migrations are in the correct path
 *  3. Model persistence works for all 4 entities
 *  4. Relationships work correctly
 *  5. Tenant isolation — no tenant_id column
 *  6. InventoryServiceProvider loads correctly
 *  7. UUID conventions verified
 *  8. AVCO fields present on InventoryItem and StockLevel
 *  9. Default values correct (status, warehouse_code, costs)
 * 10. GRN lifecycle status helpers work correctly
 */
class InventoryFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_items_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('inventory_items'),
            'inventory_items table must exist in the tenant schema'
        );
    }

    /** @test */
    public function stock_levels_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('stock_levels'),
            'stock_levels table must exist in the tenant schema'
        );
    }

    /** @test */
    public function goods_receipt_notes_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('goods_receipt_notes'),
            'goods_receipt_notes table must exist in the tenant schema'
        );
    }

    /** @test */
    public function grn_items_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('grn_items'),
            'grn_items table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/inventory/2026_09_29_200001_create_inventory_items_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/inventory/2026_09_29_200002_create_stock_levels_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/inventory/2026_09_29_200003_create_goods_receipt_notes_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/inventory/2026_09_29_200004_create_grn_items_table.php')
        );
    }

    /** @test */
    public function inventory_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_29_200001_create_inventory_items_table.php')
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_items_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('inventory_items', 'tenant_id'),
            'inventory_items must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function stock_levels_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('stock_levels', 'tenant_id'),
            'stock_levels must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function goods_receipt_notes_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('goods_receipt_notes', 'tenant_id'),
            'goods_receipt_notes must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function grn_items_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('grn_items', 'tenant_id'),
            'grn_items must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // AVCO Schema Tests (OD-009)
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_items_table_has_average_unit_cost_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('inventory_items', 'average_unit_cost'),
            'inventory_items must have average_unit_cost column for AVCO (OD-009)'
        );
    }

    /** @test */
    public function stock_levels_table_has_avco_columns(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('stock_levels', 'average_unit_cost'),
            'stock_levels must have average_unit_cost column for AVCO (OD-009)'
        );
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('stock_levels', 'quantity_on_hand'),
            'stock_levels must have quantity_on_hand column'
        );
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('stock_levels', 'total_value'),
            'stock_levels must have total_value column'
        );
    }

    /** @test */
    public function grn_items_table_has_unit_cost_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('grn_items', 'unit_cost'),
            'grn_items must have unit_cost column to feed AVCO calculation (OD-009)'
        );
    }

    // -------------------------------------------------------------------------
    // Model Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_item_can_be_created_and_retrieved(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-001',
            'name'            => 'Portland Cement 50kg',
            'unit_of_measure' => 'bag',
        ]);

        $this->assertNotNull($item->id);
        $this->assertEquals('test-tenant', $item->tenant_slug);
        $this->assertEquals('MAT-001', $item->item_code);
        $this->assertEquals('active', $item->status);
        $this->assertEquals('0.000000', $item->average_unit_cost);
    }

    /** @test */
    public function stock_level_can_be_created_with_default_warehouse(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-002',
            'name'            => 'Steel Rebar 12mm',
            'unit_of_measure' => 'ton',
        ]);

        $stockLevel = StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
        ]);

        $this->assertNotNull($stockLevel->id);
        $this->assertEquals('DEFAULT', $stockLevel->warehouse_code);
        $this->assertEquals('0.000000', $stockLevel->quantity_on_hand);
        $this->assertEquals('0.000000', $stockLevel->average_unit_cost);
        $this->assertEquals('0.000000', $stockLevel->total_value);
    }

    /** @test */
    public function goods_receipt_note_can_be_created_with_default_draft_status(): void
    {
        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-2026-001',
        ]);

        $this->assertNotNull($grn->id);
        $this->assertEquals('draft', $grn->status);
        $this->assertEquals('DEFAULT', $grn->warehouse_code);
        $this->assertTrue($grn->isDraft());
    }

    /** @test */
    public function grn_item_can_be_created_and_linked_to_grn_and_inventory_item(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-003',
            'name'            => 'Sand (Fine)',
            'unit_of_measure' => 'm3',
        ]);

        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-2026-002',
        ]);

        $grnItem = GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 50.0,
            'line_total'        => 500.0,
        ]);

        $this->assertNotNull($grnItem->id);
        $this->assertEquals($grn->id, $grnItem->grn_id);
        $this->assertEquals($item->id, $grnItem->inventory_item_id);
        $this->assertEquals('10.000000', $grnItem->quantity_received);
        $this->assertEquals('50.000000', $grnItem->unit_cost);
        $this->assertEquals('500.000000', $grnItem->line_total);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_item_has_many_stock_levels(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-010',
            'name'            => 'Gravel',
            'unit_of_measure' => 'm3',
        ]);

        StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
            'warehouse_code'    => 'WH-A',
        ]);
        StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
            'warehouse_code'    => 'WH-B',
        ]);

        $this->assertCount(2, $item->stockLevels);
    }

    /** @test */
    public function stock_level_belongs_to_inventory_item(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-011',
            'name'            => 'Brick',
            'unit_of_measure' => 'pcs',
        ]);

        $stockLevel = StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
        ]);

        $this->assertEquals($item->id, $stockLevel->inventoryItem->id);
        $this->assertEquals('MAT-011', $stockLevel->inventoryItem->item_code);
    }

    /** @test */
    public function goods_receipt_note_has_many_grn_items(): void
    {
        $item1 = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-020',
            'name'            => 'Cement',
            'unit_of_measure' => 'bag',
        ]);
        $item2 = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-021',
            'name'            => 'Sand',
            'unit_of_measure' => 'm3',
        ]);

        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-REL-001',
        ]);

        GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item1->id,
            'quantity_received' => 100,
            'unit_cost'         => 25,
            'line_total'        => 2500,
        ]);
        GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item2->id,
            'quantity_received' => 20,
            'unit_cost'         => 80,
            'line_total'        => 1600,
        ]);

        $this->assertCount(2, $grn->items);
    }

    /** @test */
    public function grn_item_belongs_to_goods_receipt_note(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-030',
            'name'            => 'Paint',
            'unit_of_measure' => 'liter',
        ]);

        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-BT-001',
        ]);

        $grnItem = GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item->id,
            'quantity_received' => 5,
            'unit_cost'         => 40,
            'line_total'        => 200,
        ]);

        $this->assertEquals($grn->id, $grnItem->grn->id);
        $this->assertEquals('GRN-BT-001', $grnItem->grn->grn_number);
    }

    /** @test */
    public function grn_item_belongs_to_inventory_item(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-031',
            'name'            => 'Tile',
            'unit_of_measure' => 'm2',
        ]);

        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-BT-002',
        ]);

        $grnItem = GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item->id,
            'quantity_received' => 50,
            'unit_cost'         => 30,
            'line_total'        => 1500,
        ]);

        $this->assertEquals($item->id, $grnItem->inventoryItem->id);
        $this->assertEquals('MAT-031', $grnItem->inventoryItem->item_code);
    }

    /** @test */
    public function inventory_item_has_many_grn_items(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'MAT-040',
            'name'            => 'Pipe 4 inch',
            'unit_of_measure' => 'm',
        ]);

        $grn1 = GoodsReceiptNote::create(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-M-001']);
        $grn2 = GoodsReceiptNote::create(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-M-002']);

        GRNItem::create(['grn_id' => $grn1->id, 'inventory_item_id' => $item->id, 'quantity_received' => 10, 'unit_cost' => 20, 'line_total' => 200]);
        GRNItem::create(['grn_id' => $grn2->id, 'inventory_item_id' => $item->id, 'quantity_received' => 15, 'unit_cost' => 22, 'line_total' => 330]);

        $this->assertCount(2, $item->grnItems);
    }

    // -------------------------------------------------------------------------
    // UUID Convention Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function all_inventory_models_use_uuid_primary_keys(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'UUID-001',
            'name'            => 'UUID Test Item',
            'unit_of_measure' => 'pcs',
        ]);

        $stockLevel = StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
        ]);

        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-UUID-001',
        ]);

        $grnItem = GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item->id,
            'quantity_received' => 1,
            'unit_cost'         => 10,
            'line_total'        => 10,
        ]);

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        $this->assertMatchesRegularExpression($uuidPattern, $item->id, 'InventoryItem must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $stockLevel->id, 'StockLevel must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $grn->id, 'GoodsReceiptNote must use UUID');
        $this->assertMatchesRegularExpression($uuidPattern, $grnItem->id, 'GRNItem must use UUID');
    }

    // -------------------------------------------------------------------------
    // ServiceProvider Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_service_provider_is_registered(): void
    {
        $this->assertContains(
            \App\Providers\InventoryServiceProvider::class,
            array_keys(app()->getLoadedProviders()),
            'InventoryServiceProvider must be registered'
        );
    }

    /** @test */
    public function inventory_service_provider_instance_is_available(): void
    {
        $this->assertNotNull(
            app()->getProvider(\App\Providers\InventoryServiceProvider::class)
        );
    }

    // -------------------------------------------------------------------------
    // Status Helper Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function goods_receipt_note_status_helpers_work_correctly(): void
    {
        $grn = new GoodsReceiptNote(['status' => 'draft']);
        $this->assertTrue($grn->isDraft());
        $this->assertFalse($grn->isConfirmed());
        $this->assertFalse($grn->isCancelled());

        $grn->status = 'confirmed';
        $this->assertTrue($grn->isConfirmed());
        $this->assertFalse($grn->isDraft());

        $grn->status = 'cancelled';
        $this->assertTrue($grn->isCancelled());
        $this->assertFalse($grn->isConfirmed());
    }

    /** @test */
    public function inventory_item_status_helpers_work_correctly(): void
    {
        $item = new InventoryItem(['status' => 'active']);
        $this->assertTrue($item->isActive());
        $this->assertFalse($item->isInactive());

        $item->status = 'inactive';
        $this->assertTrue($item->isInactive());
        $this->assertFalse($item->isActive());
    }

    // -------------------------------------------------------------------------
    // Default Values Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_item_has_correct_default_values(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'DEF-001',
            'name'            => 'Default Test Item',
            'unit_of_measure' => 'pcs',
        ]);

        $this->assertEquals('active', $item->status);
        $this->assertEquals('0.000000', $item->average_unit_cost);
    }

    /** @test */
    public function stock_level_has_correct_default_values(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'DEF-002',
            'name'            => 'Default Stock Item',
            'unit_of_measure' => 'kg',
        ]);

        $stockLevel = StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
        ]);

        $this->assertEquals('DEFAULT', $stockLevel->warehouse_code);
        $this->assertEquals('0.000000', $stockLevel->quantity_on_hand);
        $this->assertEquals('0.000000', $stockLevel->average_unit_cost);
        $this->assertEquals('0.000000', $stockLevel->total_value);
    }

    /** @test */
    public function goods_receipt_note_has_correct_default_values(): void
    {
        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-DEF-001',
        ]);

        $this->assertEquals('draft', $grn->status);
        $this->assertEquals('DEFAULT', $grn->warehouse_code);
    }

    // -------------------------------------------------------------------------
    // Procurement Cross-Reference Tests (UUID only — no cross-schema FK)
    // -------------------------------------------------------------------------

    /** @test */
    public function goods_receipt_note_stores_purchase_order_id_as_uuid_reference(): void
    {
        // purchase_order_id is a UUID reference to Procurement — NO cross-schema FK
        $fakePurchaseOrderId = '550e8400-e29b-41d4-a716-446655440000';

        $grn = GoodsReceiptNote::create([
            'tenant_slug'       => 'test-tenant',
            'grn_number'        => 'GRN-PO-001',
            'purchase_order_id' => $fakePurchaseOrderId,
        ]);

        $this->assertEquals($fakePurchaseOrderId, $grn->purchase_order_id);
    }

    /** @test */
    public function grn_item_stores_po_item_id_as_uuid_reference(): void
    {
        // po_item_id is a UUID reference to Procurement POItem — NO cross-schema FK
        $fakePOItemId = '550e8400-e29b-41d4-a716-446655440001';

        $item = InventoryItem::create([
            'tenant_slug'     => 'test-tenant',
            'item_code'       => 'XREF-001',
            'name'            => 'Cross-ref Test Item',
            'unit_of_measure' => 'pcs',
        ]);

        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'test-tenant',
            'grn_number'  => 'GRN-XREF-001',
        ]);

        $grnItem = GRNItem::create([
            'grn_id'            => $grn->id,
            'inventory_item_id' => $item->id,
            'po_item_id'        => $fakePOItemId,
            'quantity_received' => 5,
            'unit_cost'         => 100,
            'line_total'        => 500,
        ]);

        $this->assertEquals($fakePOItemId, $grnItem->po_item_id);
    }

    // -------------------------------------------------------------------------
    // tenant_slug Traceability Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function inventory_item_stores_tenant_slug_for_traceability(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'acme-construction',
            'item_code'       => 'SLUG-001',
            'name'            => 'Slug Test Item',
            'unit_of_measure' => 'pcs',
        ]);

        $this->assertEquals('acme-construction', $item->tenant_slug);
    }

    /** @test */
    public function stock_level_stores_tenant_slug_for_traceability(): void
    {
        $item = InventoryItem::create([
            'tenant_slug'     => 'acme-construction',
            'item_code'       => 'SLUG-002',
            'name'            => 'Slug Stock Item',
            'unit_of_measure' => 'kg',
        ]);

        $stockLevel = StockLevel::create([
            'tenant_slug'       => 'acme-construction',
            'inventory_item_id' => $item->id,
        ]);

        $this->assertEquals('acme-construction', $stockLevel->tenant_slug);
    }

    /** @test */
    public function goods_receipt_note_stores_tenant_slug_for_traceability(): void
    {
        $grn = GoodsReceiptNote::create([
            'tenant_slug' => 'acme-construction',
            'grn_number'  => 'GRN-SLUG-001',
        ]);

        $this->assertEquals('acme-construction', $grn->tenant_slug);
    }
}
