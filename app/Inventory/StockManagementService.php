<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * StockManagementService — Application/Service Layer for CAP-071 (Stock Management).
 *
 * Architectural constraints (TASK-008B / A1 MOD-09):
 *  - Implements Public Contracts for stock-level reads and inventory item queries.
 *  - Does NOT perform stock mutations (→ GoodsReceiptService for receipts).
 *  - Does NOT depend on HTTP layer, Procurement business logic, or Accounting.
 *  - Tenant context resolved by caller — never cross-tenant access.
 *  - Negative stock is NOT permitted in V1 — OD-009 §3.1.
 *    Any operation that would reduce stock below zero must be rejected here.
 *
 * Public Contracts (A1 MOD-09 / MODULE_BOUNDARIES):
 *  - getStockLevel(itemId, warehouseCode): StockLevel
 *  - getInventoryItem(itemId): InventoryItem
 *  - getTotalStockQuantity(itemId): float
 *
 * TASK-008B — Inventory Application/Service Layer
 */
class StockManagementService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts
    // -------------------------------------------------------------------------

    /**
     * getInventoryItem — retrieve an InventoryItem by ID.
     *
     * Public contract for cross-module consumers.
     *
     * @throws InvalidArgumentException If not found or empty ID.
     */
    public function getInventoryItem(string $inventoryItemId): InventoryItem
    {
        if (empty($inventoryItemId)) {
            throw new InvalidArgumentException('inventoryItemId must not be empty.');
        }

        $item = InventoryItem::find($inventoryItemId);

        if ($item === null) {
            throw new InvalidArgumentException("InventoryItem [{$inventoryItemId}] not found.");
        }

        return $item;
    }

    /**
     * getStockLevel — retrieve the StockLevel for a specific item and warehouse.
     *
     * Public contract for cross-module consumers.
     *
     * @throws InvalidArgumentException If not found or empty IDs.
     */
    public function getStockLevel(string $inventoryItemId, string $warehouseCode = 'DEFAULT'): StockLevel
    {
        if (empty($inventoryItemId)) {
            throw new InvalidArgumentException('inventoryItemId must not be empty.');
        }

        $stockLevel = StockLevel::where('inventory_item_id', $inventoryItemId)
            ->where('warehouse_code', $warehouseCode)
            ->first();

        if ($stockLevel === null) {
            throw new InvalidArgumentException(
                "StockLevel for item [{$inventoryItemId}] in warehouse [{$warehouseCode}] not found."
            );
        }

        return $stockLevel;
    }

    /**
     * getTotalStockQuantity — get the total quantity on hand across all warehouses for an item.
     *
     * Returns 0.0 if no stock levels exist for the item.
     */
    public function getTotalStockQuantity(string $inventoryItemId): float
    {
        if (empty($inventoryItemId)) {
            throw new InvalidArgumentException('inventoryItemId must not be empty.');
        }

        return (float) StockLevel::where('inventory_item_id', $inventoryItemId)
            ->sum('quantity_on_hand');
    }

    /**
     * assertSufficientStock — verify that sufficient stock exists before an issue/consumption.
     *
     * Enforces the V1 negative-stock prohibition (OD-009 §3.1).
     *
     * @throws InvalidArgumentException If insufficient stock would result in negative quantity.
     */
    public function assertSufficientStock(
        string $inventoryItemId,
        string $warehouseCode,
        float  $requiredQuantity,
    ): void {
        if ($requiredQuantity <= 0) {
            throw new InvalidArgumentException('requiredQuantity must be greater than zero.');
        }

        $stockLevel = StockLevel::where('inventory_item_id', $inventoryItemId)
            ->where('warehouse_code', $warehouseCode)
            ->first();

        $currentQty = $stockLevel ? (float) $stockLevel->quantity_on_hand : 0.0;

        if ($currentQty < $requiredQuantity) {
            throw new InvalidArgumentException(
                "Insufficient stock for item [{$inventoryItemId}] in warehouse [{$warehouseCode}]. "
                . "Available: {$currentQty}, Required: {$requiredQuantity}. "
                . "Negative stock is not permitted in V1 (OD-009 §3.1)."
            );
        }
    }

    /**
     * listStockLevels — list all StockLevel records for a given InventoryItem.
     *
     * Returns an empty collection if no stock levels exist.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StockLevel>
     */
    public function listStockLevels(string $inventoryItemId): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($inventoryItemId)) {
            throw new InvalidArgumentException('inventoryItemId must not be empty.');
        }

        return StockLevel::where('inventory_item_id', $inventoryItemId)->get();
    }

    // -------------------------------------------------------------------------
    // Stock Issue (OUT) — TASK-012B
    // -------------------------------------------------------------------------

    /**
     * issueStock — issue/consume stock from a warehouse (OUT movement).
     *
     * Enforces negative-stock prohibition (OD-009 §3.1).
     * Dispatches StockIssued event for Cost Control integration.
     * Records StockMovement for audit trail.
     *
     * @throws InvalidArgumentException If insufficient stock or invalid parameters.
     */
    public function issueStock(
        string      $tenantSlug,
        string      $inventoryItemId,
        string      $warehouseCode,
        float       $quantity,
        string|null $projectId = null,
        string|null $referenceType = null,
        string|null $referenceId = null,
        string|null $notes = null,
        string|null $actorId = null,
    ): StockMovement {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($inventoryItemId)) throw new InvalidArgumentException('inventoryItemId is required.');
        if (empty($warehouseCode)) throw new InvalidArgumentException('warehouseCode is required.');
        if ($quantity <= 0) throw new InvalidArgumentException('quantity must be greater than zero.');

        // Verify item exists
        $item = $this->getInventoryItem($inventoryItemId);

        // Enforce negative-stock prohibition
        $this->assertSufficientStock($inventoryItemId, $warehouseCode, $quantity);

        return DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $inventoryItemId, $warehouseCode, $quantity,
            $projectId, $referenceType, $referenceId, $notes, $actorId, $item
        ) {
            // Get current stock level
            $stockLevel = StockLevel::where('inventory_item_id', $inventoryItemId)
                ->where('warehouse_code', $warehouseCode)
                ->lockForUpdate()
                ->first();

            $unitCost   = (float) $stockLevel->average_unit_cost;
            $totalValue = round($quantity * $unitCost, 6);

            // Reduce stock level
            $stockLevel->quantity_on_hand = (float) $stockLevel->quantity_on_hand - $quantity;
            $stockLevel->total_value      = round((float) $stockLevel->quantity_on_hand * $unitCost, 6);
            $stockLevel->save();

            // Record movement
            $movement = StockMovement::create([
                'tenant_slug'         => $tenantSlug,
                'inventory_item_id'   => $inventoryItemId,
                'movement_type'       => 'out',
                'quantity'            => $quantity,
                'unit_cost'           => $unitCost,
                'total_value'         => $totalValue,
                'from_warehouse_code' => $warehouseCode,
                'to_warehouse_code'   => null,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'notes'               => $notes,
                'performed_by'        => $actorId,
            ]);

            // Audit
            $this->auditService->logEvent(
                'inventory.stock.issued',
                $actorId,
                [
                    'tenant_slug'       => $tenantSlug,
                    'subject_type'      => StockMovement::class,
                    'subject_id'        => $movement->id,
                    'after'             => [
                        'inventory_item_id' => $inventoryItemId,
                        'warehouse_code'    => $warehouseCode,
                        'quantity'          => $quantity,
                        'unit_cost'         => $unitCost,
                    ],
                ]
            );

            // Dispatch event for Cost Control integration
            Event::dispatch(new Events\StockIssued(
                tenantSlug:       $tenantSlug,
                inventoryItemId:  $inventoryItemId,
                warehouseCode:    $warehouseCode,
                quantity:         $quantity,
                unitCost:         $unitCost,
                totalValue:       $totalValue,
                projectId:        $projectId,
                referenceId:      $referenceId,
                performedBy:      $actorId,
            ));

            return $movement;
        });
    }

    // -------------------------------------------------------------------------
    // Stock Transfer — TASK-012B
    // -------------------------------------------------------------------------

    /**
     * transferStock — transfer stock between warehouses.
     *
     * Reduces source warehouse stock and increases destination warehouse stock.
     * AVCO is preserved — unit cost carries over to destination.
     * Records StockMovement for audit trail.
     *
     * @throws InvalidArgumentException If insufficient source stock or invalid parameters.
     */
    public function transferStock(
        string      $tenantSlug,
        string      $inventoryItemId,
        string      $fromWarehouseCode,
        string      $toWarehouseCode,
        float       $quantity,
        string|null $notes = null,
        string|null $actorId = null,
    ): StockMovement {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($inventoryItemId)) throw new InvalidArgumentException('inventoryItemId is required.');
        if (empty($fromWarehouseCode)) throw new InvalidArgumentException('fromWarehouseCode is required.');
        if (empty($toWarehouseCode)) throw new InvalidArgumentException('toWarehouseCode is required.');
        if ($fromWarehouseCode === $toWarehouseCode) throw new InvalidArgumentException('Source and destination warehouses must be different.');
        if ($quantity <= 0) throw new InvalidArgumentException('quantity must be greater than zero.');

        // Verify item exists
        $this->getInventoryItem($inventoryItemId);

        // Enforce negative-stock prohibition on source
        $this->assertSufficientStock($inventoryItemId, $fromWarehouseCode, $quantity);

        return DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $inventoryItemId, $fromWarehouseCode, $toWarehouseCode,
            $quantity, $notes, $actorId
        ) {
            // Get source stock level
            $sourceLevel = StockLevel::where('inventory_item_id', $inventoryItemId)
                ->where('warehouse_code', $fromWarehouseCode)
                ->lockForUpdate()
                ->first();

            $unitCost = (float) $sourceLevel->average_unit_cost;

            // Reduce source
            $sourceLevel->quantity_on_hand = (float) $sourceLevel->quantity_on_hand - $quantity;
            $sourceLevel->total_value      = round((float) $sourceLevel->quantity_on_hand * $unitCost, 6);
            $sourceLevel->save();

            // Get or create destination stock level
            $destLevel = StockLevel::firstOrCreate(
                [
                    'inventory_item_id' => $inventoryItemId,
                    'warehouse_code'    => $toWarehouseCode,
                ],
                [
                    'tenant_slug'       => $tenantSlug,
                    'quantity_on_hand'  => 0,
                    'average_unit_cost' => $unitCost,
                    'total_value'       => 0,
                ]
            );

            // Apply AVCO to destination
            $existingQty  = (float) $destLevel->quantity_on_hand;
            $existingAvg  = (float) $destLevel->average_unit_cost;
            $newTotalQty  = $existingQty + $quantity;
            $newAvgCost   = $newTotalQty > 0
                ? round(($existingQty * $existingAvg + $quantity * $unitCost) / $newTotalQty, 6)
                : $unitCost;

            $destLevel->quantity_on_hand  = $newTotalQty;
            $destLevel->average_unit_cost = $newAvgCost;
            $destLevel->total_value       = round($newTotalQty * $newAvgCost, 6);
            $destLevel->save();

            // Record movement
            $movement = StockMovement::create([
                'tenant_slug'         => $tenantSlug,
                'inventory_item_id'   => $inventoryItemId,
                'movement_type'       => 'transfer',
                'quantity'            => $quantity,
                'unit_cost'           => $unitCost,
                'total_value'         => round($quantity * $unitCost, 6),
                'from_warehouse_code' => $fromWarehouseCode,
                'to_warehouse_code'   => $toWarehouseCode,
                'reference_type'      => null,
                'reference_id'        => null,
                'notes'               => $notes,
                'performed_by'        => $actorId,
            ]);

            // Audit
            $this->auditService->logEvent(
                'inventory.stock.transferred',
                $actorId,
                [
                    'tenant_slug'  => $tenantSlug,
                    'subject_type' => StockMovement::class,
                    'subject_id'   => $movement->id,
                    'after'        => [
                        'inventory_item_id'  => $inventoryItemId,
                        'from_warehouse'     => $fromWarehouseCode,
                        'to_warehouse'       => $toWarehouseCode,
                        'quantity'           => $quantity,
                    ],
                ]
            );

            // Dispatch event
            Event::dispatch(new Events\StockTransferred(
                tenantSlug:        $tenantSlug,
                inventoryItemId:   $inventoryItemId,
                fromWarehouseCode: $fromWarehouseCode,
                toWarehouseCode:   $toWarehouseCode,
                quantity:          $quantity,
                unitCost:          $unitCost,
                performedBy:       $actorId,
            ));

            return $movement;
        });
    }

    /**
     * ListInventoryItems — paginated list of InventoryItems in the active tenant schema.
     *
     * Tenant isolation: InventoryItem uses connection='tenant' (OD-001).
     * WEB-001 PHASE 4 — Inventory Items List API
     */
    public function listInventoryItems(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return InventoryItem::orderBy('name')->paginate($perPage);
    }
}
