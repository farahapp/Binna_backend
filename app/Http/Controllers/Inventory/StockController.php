<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Inventory\StockManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * StockController — CAP-071 HTTP API Layer (Stock Management).
 *
 * Architectural constraints (A1 MOD-09 / TASK-008C):
 *  - Delegates ALL business logic to StockManagementService.
 *  - Does NOT contain stock calculation, AVCO, or negative-stock logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *  - Controllers are thin: validate → delegate → respond.
 *
 * TASK-008C — Inventory HTTP API Layer (CAP-071)
 */
class StockController extends Controller
{
    public function __construct(
        private readonly StockManagementService $stockManagementService,
    ) {}

    /**
     * GET /api/v1/inventory/items/{id}
     * Retrieve an InventoryItem by ID.
     */
    public function showItem(Request $request, string $id): JsonResponse
    {
        try {
            $item = $this->stockManagementService->getInventoryItem($id);
            return response()->json($item->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Inventory item not found.'], 404);
        }
    }

    /**
     * GET /api/v1/inventory/items/{id}/stock-level
     * Retrieve the StockLevel for an item in a specific warehouse.
     * Query param: warehouse_code (default: DEFAULT)
     */
    public function showStockLevel(Request $request, string $id): JsonResponse
    {
        $warehouseCode = $request->query('warehouse_code', 'DEFAULT');

        try {
            $stockLevel = $this->stockManagementService->getStockLevel($id, (string) $warehouseCode);
            return response()->json($stockLevel->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Stock level not found.'], 404);
        }
    }

    /**
     * GET /api/v1/inventory/items/{id}/stock-levels
     * List all StockLevel records for an item (all warehouses).
     */
    public function listStockLevels(Request $request, string $id): JsonResponse
    {
        try {
            $stockLevels = $this->stockManagementService->listStockLevels($id);
            return response()->json($stockLevels->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/inventory/items/{id}/total-quantity
     * Retrieve the total quantity on hand across all warehouses.
     */
    public function totalQuantity(Request $request, string $id): JsonResponse
    {
        try {
            $total = $this->stockManagementService->getTotalStockQuantity($id);
            return response()->json(['inventory_item_id' => $id, 'total_quantity_on_hand' => $total], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/inventory/items/{id}/assert-stock
     * Assert that sufficient stock exists for a given quantity and warehouse.
     * Query params: warehouse_code, required_quantity
     */
    public function assertStock(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_code'    => ['nullable', 'string', 'max:100'],
            'required_quantity' => ['required', 'numeric', 'min:0.000001'],
        ]);

        $warehouseCode    = $validated['warehouse_code'] ?? 'DEFAULT';
        $requiredQuantity = (float) $validated['required_quantity'];

        try {
            $this->stockManagementService->assertSufficientStock($id, $warehouseCode, $requiredQuantity);
            return response()->json([
                'inventory_item_id' => $id,
                'warehouse_code'    => $warehouseCode,
                'required_quantity' => $requiredQuantity,
                'sufficient'        => true,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'sufficient' => false], 422);
        }
    }

    /** POST /api/v1/inventory/items/{id}/issue — Permission: inventory.issue */
    public function issueStock(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('inventory.issue')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'warehouse_code'  => ['required', 'string', 'max:100'],
            'quantity'        => ['required', 'numeric', 'min:0.000001'],
            'project_id'      => ['sometimes', 'nullable', 'uuid'],
            'reference_type'  => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_id'    => ['sometimes', 'nullable', 'uuid'],
            'notes'           => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        try {
            $movement = $this->stockManagementService->issueStock(
                tenantSlug:       $tenantSlug,
                inventoryItemId:  $id,
                warehouseCode:    $validated['warehouse_code'],
                quantity:         (float) $validated['quantity'],
                projectId:        $validated['project_id'] ?? null,
                referenceType:    $validated['reference_type'] ?? null,
                referenceId:      $validated['reference_id'] ?? null,
                notes:            $validated['notes'] ?? null,
                actorId:          $actorId,
            );
            return response()->json($movement->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/v1/inventory/items/{id}/transfer — Permission: inventory.transfer */
    public function transferStock(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('inventory.transfer')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'from_warehouse_code' => ['required', 'string', 'max:100'],
            'to_warehouse_code'   => ['required', 'string', 'max:100'],
            'quantity'            => ['required', 'numeric', 'min:0.000001'],
            'notes'               => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        try {
            $movement = $this->stockManagementService->transferStock(
                tenantSlug:        $tenantSlug,
                inventoryItemId:   $id,
                fromWarehouseCode: $validated['from_warehouse_code'],
                toWarehouseCode:   $validated['to_warehouse_code'],
                quantity:          (float) $validated['quantity'],
                notes:             $validated['notes'] ?? null,
                actorId:           $actorId,
            );
            return response()->json($movement->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/inventory/items
     * Paginated list of InventoryItems in the active tenant schema.
     *
     * WEB-001 PHASE 4 — Inventory Items List API
     */
    public function listItems(Request $request): JsonResponse
    {
        $result = $this->stockManagementService->listInventoryItems();

        return response()->json([
            'data' => array_map(fn ($i) => $i->toArray(), $result->items()),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ], 200);
    }
}
