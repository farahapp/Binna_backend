<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Inventory\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GoodsReceiptController — CAP-070 HTTP API Layer (GRN & Goods Receipt).
 *
 * Architectural constraints (A1 MOD-09 / TASK-008C):
 *  - Delegates ALL business logic to GoodsReceiptService.
 *  - Does NOT contain AVCO calculation, GRN business rules, or audit logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *  - Controllers are thin: validate → delegate → respond.
 *
 * TASK-008C — Inventory HTTP API Layer (CAP-070)
 */
class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceiptService,
    ) {}

    /**
     * GET /api/v1/inventory/grns
     * Return a paginated list of GRNs for the current tenant.
     *
     * WEB-001P — OI-4 resolution.
     * Tenant isolation: physical (Schema-per-Tenant / OD-001) — no slug filter needed.
     */
    public function list(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $paginator = $this->goodsReceiptService->listGRNs($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ], 200);
    }

    /**
     * POST /api/v1/inventory/grns
     * Create a new GRN in draft status.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'grn_number'        => ['nullable', 'string', 'max:100'],
            'purchase_order_id' => ['nullable', 'string', 'uuid'],
            'supplier_id'       => ['nullable', 'string', 'uuid'],
            'warehouse_code'    => ['nullable', 'string', 'max:100'],
            'received_date'     => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $actorId      = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $grn = $this->goodsReceiptService->createGRN($validated, $actorId);
            return response()->json($grn->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/inventory/grns/{id}
     * Retrieve a GRN by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $grn = $this->goodsReceiptService->getGRN($id);
            return response()->json($grn->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'GRN not found.'], 404);
        }
    }

    /**
     * POST /api/v1/inventory/grns/{id}/items
     * Add a line item to a draft GRN.
     */
    public function addItem(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'string', 'uuid'],
            'quantity_received' => ['required', 'numeric', 'min:0.000001'],
            'unit_cost'         => ['required', 'numeric', 'min:0'],
            'po_item_id'        => ['nullable', 'string', 'uuid'],
            'notes'             => ['nullable', 'string'],
        ]);

        try {
            $item = $this->goodsReceiptService->addGRNItem($id, $validated);
            return response()->json($item->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/inventory/grns/{id}/confirm
     * Confirm a draft GRN — updates stock levels with AVCO and emits GoodsReceived.
     */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $actorId      = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);

        try {
            $grn = $this->goodsReceiptService->confirmGRN($id, $tenantContext->slug, $actorId);
            return response()->json($grn->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/inventory/grns/{id}/cancel
     * Cancel a draft GRN.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $actorId      = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);

        try {
            $grn = $this->goodsReceiptService->cancelGRN($id, $tenantContext->slug, $actorId);
            return response()->json($grn->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
