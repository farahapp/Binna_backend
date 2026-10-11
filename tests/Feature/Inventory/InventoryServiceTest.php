<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Audit\AuditEntry;
use App\Inventory\Events\GoodsReceived;
use App\Inventory\GoodsReceiptNote;
use App\Inventory\GoodsReceiptService;
use App\Inventory\GRNItem;
use App\Inventory\InventoryItem;
use App\Inventory\StockLevel;
use App\Inventory\StockManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for Inventory Application/Service Layer — TASK-008B.
 *
 * Verifies:
 *  1. GRN creation and lifecycle (draft → confirmed → cancelled)
 *  2. GRN item addition and validation
 *  3. AVCO calculation (first receipt, same cost, different cost)
 *  4. Negative stock rejection (OD-009 §3.1)
 *  5. Tenant isolation
 *  6. Audit integration
 *  7. GoodsReceived domain event (emitted on success, not on failure)
 *  8. Public contracts (getGRN, getInventoryItem, getStockLevel)
 *  9. Procurement boundary (UUID reference only)
 * 10. Service registration
 */
class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private GoodsReceiptService $goodsReceiptService;
    private StockManagementService $stockManagementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->goodsReceiptService    = app(GoodsReceiptService::class);
        $this->stockManagementService = app(StockManagementService::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeItem(?string $code = null, string $tenant = 'test-tenant'): InventoryItem
    {
        $code ??= 'MAT-'.strtoupper(substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12));

        return InventoryItem::create([
            'tenant_slug'     => $tenant,
            'item_code'       => $code,
            'name'            => "Item {$code}",
            'unit_of_measure' => 'pcs',
        ]);
    }

    private function makeGRN(string $tenant = 'test-tenant', array $extra = []): GoodsReceiptNote
    {
        return $this->goodsReceiptService->createGRN(array_merge([
            'tenant_slug' => $tenant,
            'grn_number'  => 'GRN-TEST-001',
        ], $extra));
    }

    // =========================================================================
    // 1. GRN Creation
    // =========================================================================

    /** @test */
    public function grn_can_be_created_in_draft_status(): void
    {
        $grn = $this->makeGRN();

        $this->assertNotNull($grn->id);
        $this->assertEquals('draft', $grn->status);
        $this->assertEquals('test-tenant', $grn->tenant_slug);
        $this->assertEquals('GRN-TEST-001', $grn->grn_number);
        $this->assertTrue($grn->isDraft());
    }

    /** @test */
    public function grn_creation_requires_tenant_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant_slug is required');

        $this->goodsReceiptService->createGRN([]);
    }

    /** @test */
    public function grn_stores_purchase_order_id_as_uuid_reference(): void
    {
        $fakePOId = '550e8400-e29b-41d4-a716-446655440000';

        $grn = $this->goodsReceiptService->createGRN([
            'tenant_slug'       => 'test-tenant',
            'grn_number'        => 'GRN-PO-001',
            'purchase_order_id' => $fakePOId,
        ]);

        $this->assertEquals($fakePOId, $grn->purchase_order_id);
    }

    // =========================================================================
    // 2. GRN Item Addition
    // =========================================================================

    /** @test */
    public function grn_item_can_be_added_to_draft_grn(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $grnItem = $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 50.0,
        ]);

        $this->assertNotNull($grnItem->id);
        $this->assertEquals($grn->id, $grnItem->grn_id);
        $this->assertEquals($item->id, $grnItem->inventory_item_id);
        $this->assertEquals('10.000000', $grnItem->quantity_received);
        $this->assertEquals('50.000000', $grnItem->unit_cost);
        $this->assertEquals('500.000000', $grnItem->line_total);
    }

    /** @test */
    public function grn_item_cannot_be_added_to_confirmed_grn(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 20.0,
        ]);

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in draft status to add items');

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 3.0,
            'unit_cost'         => 20.0,
        ]);
    }

    /** @test */
    public function grn_item_requires_positive_quantity(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity_received must be greater than zero');

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 0,
            'unit_cost'         => 50.0,
        ]);
    }

    /** @test */
    public function grn_item_rejects_negative_unit_cost(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unit_cost must not be negative');

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => -10.0,
        ]);
    }

    /** @test */
    public function grn_item_rejects_nonexistent_inventory_item(): void
    {
        $grn = $this->makeGRN();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => '00000000-0000-0000-0000-000000000000',
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ]);
    }

    // =========================================================================
    // 3. GRN Confirmation
    // =========================================================================

    /** @test */
    public function grn_can_be_confirmed_and_transitions_to_confirmed_status(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 100.0,
        ]);

        $confirmed = $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertTrue($confirmed->isConfirmed());
    }

    /** @test */
    public function grn_confirmation_requires_at_least_one_item(): void
    {
        $grn = $this->makeGRN();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have at least one item');

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');
    }

    /** @test */
    public function confirmed_grn_cannot_be_confirmed_again(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ]);

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in draft status to confirm');

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');
    }

    // =========================================================================
    // 4. AVCO — Moving Weighted Average Cost (OD-009)
    // =========================================================================

    /** @test */
    public function avco_first_receipt_sets_stock_level_correctly(): void
    {
        // First receipt: 100 units @ 50.00 each
        $item = $this->makeItem('AVCO-001');
        $grn  = $this->makeGRN();

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 100.0,
            'unit_cost'         => 50.0,
        ]);

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $stockLevel = $this->stockManagementService->getStockLevel($item->id);

        $this->assertEquals('100.000000', $stockLevel->quantity_on_hand);
        $this->assertEquals('50.000000', $stockLevel->average_unit_cost);
        $this->assertEquals('5000.000000', $stockLevel->total_value);
    }

    /** @test */
    public function avco_second_receipt_at_same_cost_keeps_average_unchanged(): void
    {
        // First receipt: 100 units @ 50.00
        // Second receipt: 50 units @ 50.00
        // Expected avg: 50.00, qty: 150, total: 7500.00
        $item = $this->makeItem('AVCO-002');

        $grn1 = $this->makeGRN('test-tenant', ['grn_number' => 'GRN-AVCO-001']);
        $this->goodsReceiptService->addGRNItem($grn1->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 100.0,
            'unit_cost'         => 50.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn1->id, 'test-tenant');

        $grn2 = $this->goodsReceiptService->createGRN(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-AVCO-002']);
        $this->goodsReceiptService->addGRNItem($grn2->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 50.0,
            'unit_cost'         => 50.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn2->id, 'test-tenant');

        $stockLevel = $this->stockManagementService->getStockLevel($item->id);

        $this->assertEquals('150.000000', $stockLevel->quantity_on_hand);
        $this->assertEquals('50.000000', $stockLevel->average_unit_cost);
        $this->assertEquals('7500.000000', $stockLevel->total_value);
    }

    /** @test */
    public function avco_second_receipt_at_different_cost_recalculates_average(): void
    {
        // First receipt:  100 units @ 50.00 → value = 5000
        // Second receipt:  50 units @ 80.00 → value = 4000
        // Total qty: 150, total value: 9000
        // Expected avg: 9000 / 150 = 60.00
        $item = $this->makeItem('AVCO-003');

        $grn1 = $this->goodsReceiptService->createGRN(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-DIFF-001']);
        $this->goodsReceiptService->addGRNItem($grn1->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 100.0,
            'unit_cost'         => 50.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn1->id, 'test-tenant');

        $grn2 = $this->goodsReceiptService->createGRN(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-DIFF-002']);
        $this->goodsReceiptService->addGRNItem($grn2->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 50.0,
            'unit_cost'         => 80.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn2->id, 'test-tenant');

        $stockLevel = $this->stockManagementService->getStockLevel($item->id);

        $this->assertEquals('150.000000', $stockLevel->quantity_on_hand);
        // AVCO: (100×50 + 50×80) / 150 = (5000 + 4000) / 150 = 9000/150 = 60.00
        $this->assertEquals('60.000000', $stockLevel->average_unit_cost);
        $this->assertEquals('9000.000000', $stockLevel->total_value);
    }

    /** @test */
    public function avco_three_receipts_at_varying_costs_produces_correct_average(): void
    {
        // Receipt 1: 200 units @ 10.00 → value = 2000
        // Receipt 2: 100 units @ 20.00 → value = 2000 → running: 300 units, avg = 4000/300 = 13.333...
        // Receipt 3:  50 units @ 30.00 → value = 1500 → running: 350 units, total = 5500, avg = 5500/350 ≈ 15.714...
        $item = $this->makeItem('AVCO-004');

        foreach ([
            ['qty' => 200.0, 'cost' => 10.0, 'num' => '001'],
            ['qty' => 100.0, 'cost' => 20.0, 'num' => '002'],
            ['qty' =>  50.0, 'cost' => 30.0, 'num' => '003'],
        ] as $receipt) {
            $grn = $this->goodsReceiptService->createGRN([
                'tenant_slug' => 'test-tenant',
                'grn_number'  => "GRN-3R-{$receipt['num']}",
            ]);
            $this->goodsReceiptService->addGRNItem($grn->id, [
                'inventory_item_id' => $item->id,
                'quantity_received' => $receipt['qty'],
                'unit_cost'         => $receipt['cost'],
            ]);
            $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');
        }

        $stockLevel = $this->stockManagementService->getStockLevel($item->id);

        $this->assertEquals('350.000000', $stockLevel->quantity_on_hand);

        // Expected total value: 200×10 + 100×20 + 50×30 = 2000 + 2000 + 1500 = 5500
        $expectedAvg = 5500.0 / 350.0;
        $this->assertEqualsWithDelta($expectedAvg, (float) $stockLevel->average_unit_cost, 0.000001);

        $expectedTotal = 350.0 * $expectedAvg;
        $this->assertEqualsWithDelta($expectedTotal, (float) $stockLevel->total_value, 0.001);
    }

    /** @test */
    public function avco_quantity_and_value_are_consistent(): void
    {
        $item = $this->makeItem('AVCO-005');

        $grn = $this->makeGRN();
        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 75.0,
            'unit_cost'         => 40.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $stockLevel = $this->stockManagementService->getStockLevel($item->id);

        // total_value must equal quantity_on_hand × average_unit_cost
        $expectedTotal = (float) $stockLevel->quantity_on_hand * (float) $stockLevel->average_unit_cost;
        $this->assertEqualsWithDelta($expectedTotal, (float) $stockLevel->total_value, 0.000001);
    }

    // =========================================================================
    // 5. Negative Stock Rejection (OD-009 §3.1)
    // =========================================================================

    /** @test */
    public function assert_sufficient_stock_rejects_when_stock_is_insufficient(): void
    {
        $item = $this->makeItem('NEG-001');

        // Receive 10 units
        $grn = $this->makeGRN();
        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 50.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        // Try to assert 15 units — should fail
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $this->stockManagementService->assertSufficientStock($item->id, 'DEFAULT', 15.0);
    }

    /** @test */
    public function assert_sufficient_stock_passes_when_stock_is_sufficient(): void
    {
        $item = $this->makeItem('NEG-002');

        $grn = $this->makeGRN('test-tenant', ['grn_number' => 'GRN-NEG-002']);
        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 20.0,
            'unit_cost'         => 30.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        // Should not throw — 20 available, requesting 10
        $this->stockManagementService->assertSufficientStock($item->id, 'DEFAULT', 10.0);
        $this->assertTrue(true); // Reached here without exception
    }

    /** @test */
    public function assert_sufficient_stock_rejects_when_no_stock_exists(): void
    {
        $item = $this->makeItem('NEG-003');

        // No GRN confirmed — stock level does not exist
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $this->stockManagementService->assertSufficientStock($item->id, 'DEFAULT', 1.0);
    }

    /** @test */
    public function assert_sufficient_stock_rejects_zero_required_quantity(): void
    {
        $item = $this->makeItem('NEG-004');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requiredQuantity must be greater than zero');

        $this->stockManagementService->assertSufficientStock($item->id, 'DEFAULT', 0.0);
    }

    // =========================================================================
    // 6. Tenant Isolation
    // =========================================================================

    /** @test */
    public function tenant_a_stock_is_independent_from_tenant_b_stock(): void
    {
        // Tenant A receives 100 units of item-A
        $itemA = InventoryItem::create([
            'tenant_slug'     => 'tenant-a',
            'item_code'       => 'ITEM-A',
            'name'            => 'Item A',
            'unit_of_measure' => 'pcs',
        ]);

        $grnA = $this->goodsReceiptService->createGRN([
            'tenant_slug' => 'tenant-a',
            'grn_number'  => 'GRN-A-001',
        ]);
        $this->goodsReceiptService->addGRNItem($grnA->id, [
            'inventory_item_id' => $itemA->id,
            'quantity_received' => 100.0,
            'unit_cost'         => 10.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grnA->id, 'tenant-a');

        // Tenant B receives 50 units of item-B
        $itemB = InventoryItem::create([
            'tenant_slug'     => 'tenant-b',
            'item_code'       => 'ITEM-B',
            'name'            => 'Item B',
            'unit_of_measure' => 'pcs',
        ]);

        $grnB = $this->goodsReceiptService->createGRN([
            'tenant_slug' => 'tenant-b',
            'grn_number'  => 'GRN-B-001',
        ]);
        $this->goodsReceiptService->addGRNItem($grnB->id, [
            'inventory_item_id' => $itemB->id,
            'quantity_received' => 50.0,
            'unit_cost'         => 20.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grnB->id, 'tenant-b');

        // Tenant A stock is unaffected by Tenant B operations
        $stockA = $this->stockManagementService->getStockLevel($itemA->id);
        $this->assertEquals('100.000000', $stockA->quantity_on_hand);
        $this->assertEquals('10.000000', $stockA->average_unit_cost);

        // Tenant B stock is unaffected by Tenant A operations
        $stockB = $this->stockManagementService->getStockLevel($itemB->id);
        $this->assertEquals('50.000000', $stockB->quantity_on_hand);
        $this->assertEquals('20.000000', $stockB->average_unit_cost);
    }

    /** @test */
    public function grn_tenant_slug_is_stored_correctly(): void
    {
        $grn = $this->goodsReceiptService->createGRN([
            'tenant_slug' => 'acme-construction',
            'grn_number'  => 'GRN-SLUG-001',
        ]);

        $this->assertEquals('acme-construction', $grn->tenant_slug);
    }

    // =========================================================================
    // 7. Audit Integration
    // =========================================================================

    /** @test */
    public function grn_creation_logs_audit_event(): void
    {
        $this->makeGRN();

        $auditEntry = AuditEntry::where('event', 'inventory.grn.created')->first();

        $this->assertNotNull($auditEntry, 'Audit entry for inventory.grn.created must exist');
        $this->assertEquals('inventory.grn.created', $auditEntry->event);
        $this->assertEquals('test-tenant', $auditEntry->tenant_slug);
    }

    /** @test */
    public function grn_confirmation_logs_audit_event(): void
    {
        $item = $this->makeItem();
        $grn  = $this->makeGRN();

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ]);

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant', 'actor-uuid-001');

        $auditEntry = AuditEntry::where('event', 'inventory.grn.confirmed')->first();

        $this->assertNotNull($auditEntry, 'Audit entry for inventory.grn.confirmed must exist');
        $this->assertEquals('inventory.grn.confirmed', $auditEntry->event);
        $this->assertEquals('test-tenant', $auditEntry->tenant_slug);
        $this->assertEquals('actor-uuid-001', $auditEntry->actor_id);
    }

    /** @test */
    public function grn_cancellation_logs_audit_event(): void
    {
        $grn = $this->makeGRN();

        $this->goodsReceiptService->cancelGRN($grn->id, 'test-tenant', 'actor-uuid-002');

        $auditEntry = AuditEntry::where('event', 'inventory.grn.cancelled')->first();

        $this->assertNotNull($auditEntry, 'Audit entry for inventory.grn.cancelled must exist');
        $this->assertEquals('inventory.grn.cancelled', $auditEntry->event);
    }

    // =========================================================================
    // 8. GoodsReceived Domain Event
    // =========================================================================

    /** @test */
    public function goods_received_event_is_dispatched_on_successful_confirmation(): void
    {
        Event::fake([GoodsReceived::class]);

        $item = $this->makeItem('EVT-001');
        $grn  = $this->makeGRN();

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 25.0,
        ]);

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        Event::assertDispatched(GoodsReceived::class, function (GoodsReceived $event) use ($grn) {
            return $event->grnId === $grn->id
                && $event->tenantSlug === 'test-tenant'
                && $event->totalValue === 250.0;
        });
    }

    /** @test */
    public function goods_received_event_is_not_dispatched_when_grn_has_no_items(): void
    {
        Event::fake([GoodsReceived::class]);

        $grn = $this->makeGRN();

        try {
            $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');
        } catch (\InvalidArgumentException $e) {
            // Expected — GRN has no items
        }

        Event::assertNotDispatched(GoodsReceived::class);
    }

    /** @test */
    public function goods_received_event_carries_purchase_order_id(): void
    {
        Event::fake([GoodsReceived::class]);

        $fakePOId = '550e8400-e29b-41d4-a716-446655440099';
        $item     = $this->makeItem('EVT-002');

        $grn = $this->goodsReceiptService->createGRN([
            'tenant_slug'       => 'test-tenant',
            'grn_number'        => 'GRN-EVT-PO',
            'purchase_order_id' => $fakePOId,
        ]);

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ]);

        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        Event::assertDispatched(GoodsReceived::class, function (GoodsReceived $event) use ($fakePOId) {
            return $event->purchaseOrderId === $fakePOId;
        });
    }

    // =========================================================================
    // 9. Public Contracts
    // =========================================================================

    /** @test */
    public function get_grn_returns_correct_grn(): void
    {
        $grn = $this->makeGRN();

        $retrieved = $this->goodsReceiptService->getGRN($grn->id);

        $this->assertEquals($grn->id, $retrieved->id);
        $this->assertEquals('GRN-TEST-001', $retrieved->grn_number);
    }

    /** @test */
    public function get_grn_throws_for_nonexistent_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->goodsReceiptService->getGRN('00000000-0000-0000-0000-000000000000');
    }

    /** @test */
    public function get_grn_throws_for_empty_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('grnId must not be empty');

        $this->goodsReceiptService->getGRN('');
    }

    /** @test */
    public function get_inventory_item_returns_correct_item(): void
    {
        $item = $this->makeItem('CONTRACT-001');

        $retrieved = $this->stockManagementService->getInventoryItem($item->id);

        $this->assertEquals($item->id, $retrieved->id);
        $this->assertEquals('CONTRACT-001', $retrieved->item_code);
    }

    /** @test */
    public function get_inventory_item_throws_for_nonexistent_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->stockManagementService->getInventoryItem('00000000-0000-0000-0000-000000000000');
    }

    /** @test */
    public function get_stock_level_returns_correct_level(): void
    {
        $item = $this->makeItem('CONTRACT-002');
        $grn  = $this->makeGRN('test-tenant', ['grn_number' => 'GRN-CONTRACT-001']);

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 30.0,
            'unit_cost'         => 15.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $stockLevel = $this->stockManagementService->getStockLevel($item->id);

        $this->assertEquals($item->id, $stockLevel->inventory_item_id);
        $this->assertEquals('30.000000', $stockLevel->quantity_on_hand);
    }

    /** @test */
    public function get_stock_level_throws_for_nonexistent_item(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->stockManagementService->getStockLevel('00000000-0000-0000-0000-000000000000');
    }

    /** @test */
    public function get_total_stock_quantity_returns_sum_across_warehouses(): void
    {
        $item = $this->makeItem('CONTRACT-003');

        // Receive in DEFAULT warehouse
        $grn1 = $this->goodsReceiptService->createGRN(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-WH-001', 'warehouse_code' => 'WH-A']);
        $this->goodsReceiptService->addGRNItem($grn1->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 40.0,
            'unit_cost'         => 10.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn1->id, 'test-tenant');

        // Receive in WH-B warehouse
        $grn2 = $this->goodsReceiptService->createGRN(['tenant_slug' => 'test-tenant', 'grn_number' => 'GRN-WH-002', 'warehouse_code' => 'WH-B']);
        $this->goodsReceiptService->addGRNItem($grn2->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 60.0,
            'unit_cost'         => 10.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn2->id, 'test-tenant');

        $total = $this->stockManagementService->getTotalStockQuantity($item->id);

        $this->assertEquals(100.0, $total);
    }

    // =========================================================================
    // 10. GRN Cancellation
    // =========================================================================

    /** @test */
    public function draft_grn_can_be_cancelled(): void
    {
        $grn = $this->makeGRN();

        $cancelled = $this->goodsReceiptService->cancelGRN($grn->id, 'test-tenant');

        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertTrue($cancelled->isCancelled());
    }

    /** @test */
    public function confirmed_grn_cannot_be_cancelled(): void
    {
        $item = $this->makeItem('CANCEL-001');
        $grn  = $this->makeGRN('test-tenant', ['grn_number' => 'GRN-CANCEL-001']);

        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ]);
        $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('can only be cancelled from draft status');

        $this->goodsReceiptService->cancelGRN($grn->id, 'test-tenant');
    }

    // =========================================================================
    // 11. Service Registration
    // =========================================================================

    /** @test */
    public function goods_receipt_service_is_resolvable_from_container(): void
    {
        $service = app(GoodsReceiptService::class);
        $this->assertInstanceOf(GoodsReceiptService::class, $service);
    }

    /** @test */
    public function stock_management_service_is_resolvable_from_container(): void
    {
        $service = app(StockManagementService::class);
        $this->assertInstanceOf(StockManagementService::class, $service);
    }

    // =========================================================================
    // 12. Procurement Boundary
    // =========================================================================

    /** @test */
    public function grn_can_reference_procurement_po_by_uuid_without_cross_schema_dependency(): void
    {
        // Procurement PO UUID — no actual PO record needed (no cross-schema FK)
        $fakePOId = '550e8400-e29b-41d4-a716-446655440088';

        $grn = $this->goodsReceiptService->createGRN([
            'tenant_slug'       => 'test-tenant',
            'grn_number'        => 'GRN-PROC-001',
            'purchase_order_id' => $fakePOId,
        ]);

        $this->assertEquals($fakePOId, $grn->purchase_order_id);

        // GRN can be confirmed without any Procurement service involvement
        $item = $this->makeItem('PROC-001');
        $this->goodsReceiptService->addGRNItem($grn->id, [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ]);

        $confirmed = $this->goodsReceiptService->confirmGRN($grn->id, 'test-tenant');
        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertEquals($fakePOId, $confirmed->purchase_order_id);
    }
}
