<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Inventory\Events\StockIssued;
use App\Inventory\Events\StockTransferred;
use App\Inventory\InventoryItem;
use App\Inventory\StockLevel;
use App\Inventory\StockManagementService;
use App\Inventory\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Stock Movements — TASK-012A/B.
 *
 * Verifies:
 *  1. stock_movements table exists in tenant schema
 *  2. No tenant_id on stock_movements (Schema-per-Tenant)
 *  3. tenant_slug present on stock_movements
 *  4. StockMovement model uses tenant connection
 *  5. StockMovement UUID PK
 *  6. issueStock — success
 *  7. issueStock — reduces stock level
 *  8. issueStock — records OUT movement
 *  9. issueStock — dispatches StockIssued event
 * 10. issueStock — insufficient stock rejected
 * 11. issueStock — invalid quantity rejected
 * 12. issueStock — invalid item rejected
 * 13. transferStock — success
 * 14. transferStock — reduces source stock
 * 15. transferStock — increases destination stock
 * 16. transferStock — records TRANSFER movement
 * 17. transferStock — dispatches StockTransferred event
 * 18. transferStock — insufficient source stock rejected
 * 19. transferStock — same warehouse rejected
 * 20. transferStock — creates destination stock level if not exists
 * 21. StockMovement type helpers (isIn, isOut, isTransfer)
 * 22. AVCO preserved on transfer
 */
class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private StockManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockManagementService::class);
    }

    // -------------------------------------------------------------------------
    // Schema Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function stock_movements_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('stock_movements'),
            'stock_movements table must exist in the tenant schema'
        );
    }

    /** @test */
    public function stock_movements_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('stock_movements', 'tenant_id'),
            'stock_movements must NOT have a tenant_id column'
        );
    }

    /** @test */
    public function stock_movements_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('stock_movements', 'tenant_slug'),
            'stock_movements must have a tenant_slug column'
        );
    }

    /** @test */
    public function stock_movement_model_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new StockMovement())->getConnectionName());
    }

    /** @test */
    public function stock_movement_has_uuid_primary_key(): void
    {
        $movement = StockMovement::create([
            'tenant_slug'         => 'test-tenant',
            'inventory_item_id'   => \Illuminate\Support\Str::uuid()->toString(),
            'movement_type'       => 'out',
            'quantity'            => 5.0,
            'unit_cost'           => 10.0,
            'total_value'         => 50.0,
            'from_warehouse_code' => 'WH-A',
        ]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $movement->id
        );
    }

    // -------------------------------------------------------------------------
    // issueStock Tests
    // -------------------------------------------------------------------------

    private function createItemWithStock(
        string $itemCode = '',
        float  $qty = 100.0,
        float  $avgCost = 10.0,
        string $warehouse = 'DEFAULT',
    ): array {
        if ($itemCode === '') {
            $itemCode = 'ITEM-' . \Illuminate\Support\Str::random(8);
        }
        $item = InventoryItem::create([
            'tenant_slug'       => 'test-tenant',
            'item_code'         => $itemCode,
            'name'              => 'Test Item',
            'unit_of_measure'   => 'pcs',
            'average_unit_cost' => $avgCost,
            'status'            => 'active',
        ]);
        $stockLevel = StockLevel::create([
            'tenant_slug'       => 'test-tenant',
            'inventory_item_id' => $item->id,
            'warehouse_code'    => $warehouse,
            'quantity_on_hand'  => $qty,
            'average_unit_cost' => $avgCost,
            'total_value'       => $qty * $avgCost,
        ]);
        return [$item, $stockLevel];
    }

    /** @test */
    public function issue_stock_success(): void
    {
        [$item, $stockLevel] = $this->createItemWithStock(qty: 100.0, avgCost: 10.0);

        $movement = $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: $item->id,
            warehouseCode:   'DEFAULT',
            quantity:        20.0,
            actorId:         null,
        );

        $this->assertInstanceOf(StockMovement::class, $movement);
        $this->assertSame('out', $movement->movement_type);
        $this->assertEquals(20.0, (float) $movement->quantity);
    }

    /** @test */
    public function issue_stock_reduces_stock_level(): void
    {
        [$item, $stockLevel] = $this->createItemWithStock(qty: 100.0);

        $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: $item->id,
            warehouseCode:   'DEFAULT',
            quantity:        30.0,
        );

        $stockLevel->refresh();
        $this->assertEquals(70.0, (float) $stockLevel->quantity_on_hand);
    }

    /** @test */
    public function issue_stock_records_out_movement(): void
    {
        [$item] = $this->createItemWithStock(qty: 50.0);

        $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: $item->id,
            warehouseCode:   'DEFAULT',
            quantity:        10.0,
        );

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'movement_type'     => 'out',
        ]);
    }

    /** @test */
    public function issue_stock_dispatches_stock_issued_event(): void
    {
        Event::fake([StockIssued::class]);
        [$item] = $this->createItemWithStock(qty: 50.0);

        $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: $item->id,
            warehouseCode:   'DEFAULT',
            quantity:        5.0,
        );

        Event::assertDispatched(StockIssued::class);
    }

    /** @test */
    public function issue_stock_rejects_insufficient_stock(): void
    {
        [$item] = $this->createItemWithStock(qty: 10.0);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: $item->id,
            warehouseCode:   'DEFAULT',
            quantity:        50.0,
        );
    }

    /** @test */
    public function issue_stock_rejects_zero_quantity(): void
    {
        [$item] = $this->createItemWithStock();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: $item->id,
            warehouseCode:   'DEFAULT',
            quantity:        0.0,
        );
    }

    /** @test */
    public function issue_stock_rejects_invalid_item(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->issueStock(
            tenantSlug:      'test-tenant',
            inventoryItemId: \Illuminate\Support\Str::uuid()->toString(),
            warehouseCode:   'DEFAULT',
            quantity:        5.0,
        );
    }

    // -------------------------------------------------------------------------
    // transferStock Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function transfer_stock_success(): void
    {
        [$item] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $movement = $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          30.0,
        );

        $this->assertInstanceOf(StockMovement::class, $movement);
        $this->assertSame('transfer', $movement->movement_type);
    }

    /** @test */
    public function transfer_stock_reduces_source(): void
    {
        [$item, $sourceLevel] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          40.0,
        );

        $sourceLevel->refresh();
        $this->assertEquals(60.0, (float) $sourceLevel->quantity_on_hand);
    }

    /** @test */
    public function transfer_stock_increases_destination(): void
    {
        [$item] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          25.0,
        );

        $destLevel = StockLevel::where('inventory_item_id', $item->id)
            ->where('warehouse_code', 'WH-B')
            ->first();

        $this->assertNotNull($destLevel);
        $this->assertEquals(25.0, (float) $destLevel->quantity_on_hand);
    }

    /** @test */
    public function transfer_stock_records_transfer_movement(): void
    {
        [$item] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          10.0,
        );

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id'   => $item->id,
            'movement_type'       => 'transfer',
            'from_warehouse_code' => 'WH-A',
            'to_warehouse_code'   => 'WH-B',
        ]);
    }

    /** @test */
    public function transfer_stock_dispatches_stock_transferred_event(): void
    {
        Event::fake([StockTransferred::class]);
        [$item] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          10.0,
        );

        Event::assertDispatched(StockTransferred::class);
    }

    /** @test */
    public function transfer_stock_rejects_insufficient_source(): void
    {
        [$item] = $this->createItemWithStock(qty: 10.0, warehouse: 'WH-A');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          50.0,
        );
    }

    /** @test */
    public function transfer_stock_rejects_same_warehouse(): void
    {
        [$item] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-A',
            quantity:          10.0,
        );
    }

    /** @test */
    public function transfer_creates_destination_stock_level_if_not_exists(): void
    {
        [$item] = $this->createItemWithStock(qty: 100.0, warehouse: 'WH-A');

        $this->assertNull(
            StockLevel::where('inventory_item_id', $item->id)
                ->where('warehouse_code', 'WH-NEW')
                ->first()
        );

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-NEW',
            quantity:          15.0,
        );

        $this->assertNotNull(
            StockLevel::where('inventory_item_id', $item->id)
                ->where('warehouse_code', 'WH-NEW')
                ->first()
        );
    }

    /** @test */
    public function stock_movement_type_helpers(): void
    {
        $inMovement = new StockMovement(['movement_type' => 'in']);
        $this->assertTrue($inMovement->isIn());
        $this->assertFalse($inMovement->isOut());
        $this->assertFalse($inMovement->isTransfer());

        $outMovement = new StockMovement(['movement_type' => 'out']);
        $this->assertFalse($outMovement->isIn());
        $this->assertTrue($outMovement->isOut());
        $this->assertFalse($outMovement->isTransfer());

        $transferMovement = new StockMovement(['movement_type' => 'transfer']);
        $this->assertFalse($transferMovement->isIn());
        $this->assertFalse($transferMovement->isOut());
        $this->assertTrue($transferMovement->isTransfer());
    }

    /** @test */
    public function avco_is_preserved_on_transfer(): void
    {
        [$item] = $this->createItemWithStock(qty: 100.0, avgCost: 15.0, warehouse: 'WH-A');

        $this->service->transferStock(
            tenantSlug:        'test-tenant',
            inventoryItemId:   $item->id,
            fromWarehouseCode: 'WH-A',
            toWarehouseCode:   'WH-B',
            quantity:          50.0,
        );

        $destLevel = StockLevel::where('inventory_item_id', $item->id)
            ->where('warehouse_code', 'WH-B')
            ->first();

        $this->assertEquals(15.0, (float) $destLevel->average_unit_cost);
    }
}
