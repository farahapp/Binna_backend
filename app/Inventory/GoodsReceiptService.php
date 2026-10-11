<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Audit\AuditService;
use App\Inventory\Events\GoodsReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * GoodsReceiptService — Application/Service Layer for CAP-070 (GRN & Goods Receipt).
 *
 * Architectural constraints (TASK-008B / A1 MOD-09):
 *  - Implements GRN creation, item addition, and GRN confirmation.
 *  - Applies AVCO (Moving Weighted Average Cost) on confirmation — OD-009.
 *  - Enforces negative-stock prohibition — OD-009 §3.1.
 *  - Dispatches GoodsReceived domain event on successful confirmation.
 *  - Integrates with AuditService per established conventions.
 *  - MUST NOT depend on HTTP layer, Procurement business logic, or Accounting.
 *  - Procurement references are UUID-only (no cross-schema FK or business logic).
 *  - Tenant context resolved by caller — never cross-tenant access.
 *  - Does NOT contain StockManagement read responsibilities (→ StockManagementService).
 *
 * AVCO Formula (OD-009):
 *   new_avg = (existing_qty × existing_avg + received_qty × received_cost)
 *             / (existing_qty + received_qty)
 *
 * TASK-008B — Inventory Application/Service Layer
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // GRN List
    // -------------------------------------------------------------------------

    /**
     * listGRNs — return a paginated list of GoodsReceiptNotes for the current tenant.
     *
     * Tenant isolation is physical (Schema-per-Tenant / OD-001).
     * No tenant_slug filter needed — the active schema is already scoped.
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listGRNs(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return GoodsReceiptNote::orderBy('created_at', 'desc')->paginate($perPage);
    }

    // -------------------------------------------------------------------------
    // GRN Lifecycle
    // -------------------------------------------------------------------------

    /**
     * createGRN — create a new GoodsReceiptNote in draft status.
     *
     * @param array{
     *     tenant_slug: string,
     *     grn_number?: string|null,
     *     purchase_order_id?: string|null,
     *     supplier_id?: string|null,
     *     warehouse_code?: string,
     *     received_date?: string|null,
     *     notes?: string|null,
     * } $attributes
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function createGRN(array $attributes, string|null $actorId = null): GoodsReceiptNote
    {
        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }

        $grn = GoodsReceiptNote::create([
            'tenant_slug'       => $attributes['tenant_slug'],
            'grn_number'        => $attributes['grn_number'] ?? null,
            'purchase_order_id' => $attributes['purchase_order_id'] ?? null,
            'supplier_id'       => $attributes['supplier_id'] ?? null,
            'warehouse_code'    => $attributes['warehouse_code'] ?? 'DEFAULT',
            'status'            => 'draft',
            'received_date'     => $attributes['received_date'] ?? null,
            'notes'             => $attributes['notes'] ?? null,
        ]);

        $this->auditService->logEvent(
            'inventory.grn.created',
            $actorId,
            [
                'tenant_slug'  => $attributes['tenant_slug'],
                'subject_type' => GoodsReceiptNote::class,
                'subject_id'   => $grn->id,
                'after'        => ['status' => 'draft', 'grn_number' => $grn->grn_number],
            ]
        );

        return $grn;
    }

    /**
     * addGRNItem — add a line item to a draft GRN.
     *
     * @param array{
     *     inventory_item_id: string,
     *     quantity_received: float|int,
     *     unit_cost: float|int,
     *     po_item_id?: string|null,
     *     notes?: string|null,
     * } $itemAttributes
     * @throws InvalidArgumentException If GRN not found, not draft, or item data invalid.
     */
    public function addGRNItem(string $grnId, array $itemAttributes): GRNItem
    {
        if (empty($grnId)) {
            throw new InvalidArgumentException('grnId must not be empty.');
        }

        $grn = $this->getGRN($grnId);

        if (! $grn->isDraft()) {
            throw new InvalidArgumentException(
                "GRN [{$grnId}] must be in draft status to add items. Current: {$grn->status}"
            );
        }

        if (empty($itemAttributes['inventory_item_id'])) {
            throw new InvalidArgumentException('inventory_item_id is required.');
        }

        $inventoryItem = InventoryItem::find($itemAttributes['inventory_item_id']);
        if ($inventoryItem === null) {
            throw new InvalidArgumentException(
                "InventoryItem [{$itemAttributes['inventory_item_id']}] not found."
            );
        }

        $quantityReceived = (float) ($itemAttributes['quantity_received'] ?? 0);
        $unitCost         = (float) ($itemAttributes['unit_cost'] ?? 0);

        if ($quantityReceived <= 0) {
            throw new InvalidArgumentException('quantity_received must be greater than zero.');
        }

        if ($unitCost < 0) {
            throw new InvalidArgumentException('unit_cost must not be negative.');
        }

        $lineTotal = $quantityReceived * $unitCost;

        return GRNItem::create([
            'grn_id'            => $grnId,
            'inventory_item_id' => $itemAttributes['inventory_item_id'],
            'po_item_id'        => $itemAttributes['po_item_id'] ?? null,
            'quantity_received' => $quantityReceived,
            'unit_cost'         => $unitCost,
            'line_total'        => $lineTotal,
            'notes'             => $itemAttributes['notes'] ?? null,
        ]);
    }

    /**
     * confirmGRN — confirm a draft GRN, update stock levels with AVCO, and emit GoodsReceived.
     *
     * This is the authoritative operation that:
     *  1. Validates the GRN is in draft status with at least one item.
     *  2. For each GRN item, updates the StockLevel using the AVCO formula (OD-009).
     *  3. Transitions the GRN to 'confirmed'.
     *  4. Logs an audit event.
     *  5. Dispatches the GoodsReceived domain event.
     *
     * All stock updates are wrapped in a DB transaction to prevent partial state.
     *
     * @throws InvalidArgumentException If GRN not found, not draft, or has no items.
     */
    public function confirmGRN(string $grnId, string $tenantSlug, string|null $actorId = null): GoodsReceiptNote
    {
        if (empty($grnId)) {
            throw new InvalidArgumentException('grnId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenantSlug is required.');
        }

        $grn = $this->getGRN($grnId);

        if (! $grn->isDraft()) {
            throw new InvalidArgumentException(
                "GRN [{$grnId}] must be in draft status to confirm. Current: {$grn->status}"
            );
        }

        $items = $grn->items()->with('inventoryItem')->get();

        if ($items->isEmpty()) {
            throw new InvalidArgumentException(
                "GRN [{$grnId}] must have at least one item before confirmation."
            );
        }

        $totalValue = 0.0;

        DB::connection('tenant')->transaction(function () use ($grn, $items, $tenantSlug, $actorId, &$totalValue) {
            foreach ($items as $grnItem) {
                $this->applyAVCOToStockLevel(
                    tenantSlug:      $tenantSlug,
                    inventoryItemId: $grnItem->inventory_item_id,
                    warehouseCode:   $grn->warehouse_code,
                    quantityReceived: (float) $grnItem->quantity_received,
                    unitCost:        (float) $grnItem->unit_cost,
                );
                $totalValue += (float) $grnItem->line_total;
            }

            $grn->update([
                'status'      => 'confirmed',
                'received_by' => $actorId,
            ]);
        });

        $this->auditService->logEvent(
            'inventory.grn.confirmed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => GoodsReceiptNote::class,
                'subject_id'   => $grn->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'confirmed', 'total_value' => $totalValue],
            ]
        );

        Event::dispatch(new GoodsReceived(
            grnId:           $grn->id,
            tenantSlug:      $tenantSlug,
            purchaseOrderId: $grn->purchase_order_id ?? '',
            totalValue:      $totalValue,
            occurredAt:      new \DateTimeImmutable(),
        ));

        return $grn->fresh();
    }

    /**
     * cancelGRN — cancel a draft GRN (no stock changes).
     *
     * Only draft GRNs may be cancelled. Confirmed GRNs cannot be cancelled in V1.
     *
     * @throws InvalidArgumentException If GRN not found or not in draft status.
     */
    public function cancelGRN(string $grnId, string $tenantSlug, string|null $actorId = null): GoodsReceiptNote
    {
        if (empty($grnId)) {
            throw new InvalidArgumentException('grnId must not be empty.');
        }

        $grn = $this->getGRN($grnId);

        if (! $grn->isDraft()) {
            throw new InvalidArgumentException(
                "GRN [{$grnId}] can only be cancelled from draft status. Current: {$grn->status}"
            );
        }

        $grn->update(['status' => 'cancelled']);

        $this->auditService->logEvent(
            'inventory.grn.cancelled',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => GoodsReceiptNote::class,
                'subject_id'   => $grn->id,
                'before'       => ['status' => 'draft'],
                'after'        => ['status' => 'cancelled'],
            ]
        );

        return $grn->fresh();
    }

    // -------------------------------------------------------------------------
    // Public Contract: getGRN
    // -------------------------------------------------------------------------

    /**
     * getGRN — retrieve a GoodsReceiptNote by ID.
     *
     * @throws InvalidArgumentException If not found or empty ID.
     */
    public function getGRN(string $grnId): GoodsReceiptNote
    {
        if (empty($grnId)) {
            throw new InvalidArgumentException('grnId must not be empty.');
        }

        $grn = GoodsReceiptNote::find($grnId);

        if ($grn === null) {
            throw new InvalidArgumentException("GoodsReceiptNote [{$grnId}] not found.");
        }

        return $grn;
    }

    // -------------------------------------------------------------------------
    // AVCO — Moving Weighted Average Cost (OD-009)
    // -------------------------------------------------------------------------

    /**
     * applyAVCOToStockLevel — update a StockLevel record using the AVCO formula.
     *
     * AVCO Formula (OD-009):
     *   new_avg = (existing_qty × existing_avg + received_qty × received_cost)
     *             / (existing_qty + received_qty)
     *
     * Creates the StockLevel record if it does not yet exist for this
     * (tenant_slug, inventory_item_id, warehouse_code) combination.
     *
     * Uses DB-level locking (lockForUpdate) to prevent race conditions.
     *
     * @throws InvalidArgumentException If quantityReceived <= 0.
     */
    private function applyAVCOToStockLevel(
        string $tenantSlug,
        string $inventoryItemId,
        string $warehouseCode,
        float  $quantityReceived,
        float  $unitCost,
    ): StockLevel {
        if ($quantityReceived <= 0) {
            throw new InvalidArgumentException('quantityReceived must be greater than zero for AVCO update.');
        }

        // Lock the row for update to prevent concurrent AVCO miscalculation
        $stockLevel = StockLevel::where('tenant_slug', $tenantSlug)
            ->where('inventory_item_id', $inventoryItemId)
            ->where('warehouse_code', $warehouseCode)
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            // First receipt for this item/warehouse — create the StockLevel
            $stockLevel = StockLevel::create([
                'tenant_slug'       => $tenantSlug,
                'inventory_item_id' => $inventoryItemId,
                'warehouse_code'    => $warehouseCode,
                'quantity_on_hand'  => $quantityReceived,
                'average_unit_cost' => $unitCost,
                'total_value'       => $quantityReceived * $unitCost,
            ]);
        } else {
            // Subsequent receipt — apply AVCO formula
            $existingQty  = (float) $stockLevel->quantity_on_hand;
            $existingAvg  = (float) $stockLevel->average_unit_cost;

            $newQty       = $existingQty + $quantityReceived;
            $newAvg       = ($existingQty * $existingAvg + $quantityReceived * $unitCost) / $newQty;
            $newTotalValue = $newQty * $newAvg;

            $stockLevel->update([
                'quantity_on_hand'  => $newQty,
                'average_unit_cost' => $newAvg,
                'total_value'       => $newTotalValue,
            ]);
        }

        // Keep InventoryItem.average_unit_cost in sync (item-level AVCO per OD-009)
        // This is the aggregate average across all warehouses for the item.
        // For V1 with a single warehouse per item, this equals the StockLevel AVCO.
        // For multi-warehouse, this is updated per receipt as an approximation.
        $inventoryItem = InventoryItem::find($inventoryItemId);
        if ($inventoryItem !== null) {
            $inventoryItem->update([
                'average_unit_cost' => $stockLevel->average_unit_cost,
            ]);
        }

        return $stockLevel->fresh();
    }
}
