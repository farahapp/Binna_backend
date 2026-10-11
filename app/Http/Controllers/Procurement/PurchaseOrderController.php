<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Procurement\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PurchaseOrderController — CAP-063 HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-08 / TASK-007C):
 *  - Delegates ALL business logic to PurchaseOrderService.
 *  - Does NOT contain PO lifecycle logic or approval logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *
 * TASK-007C — Procurement HTTP API Layer (CAP-063)
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
    ) {}

    /**
     * POST /api/v1/procurement/purchase-orders
     * Create a new Purchase Order in draft status.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id'            => ['required', 'string', 'uuid'],
            'purchase_request_id'    => ['nullable', 'string', 'uuid'],
            'po_number'              => ['nullable', 'string', 'max:100'],
            'total_amount'           => ['nullable', 'numeric', 'min:0'],
            'currency'               => ['nullable', 'string', 'max:10'],
            'issued_date'            => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes'                  => ['nullable', 'string'],
        ]);

        $actorId = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $po = $this->purchaseOrderService->createPurchaseOrder($validated, $actorId);
            return response()->json($po->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/procurement/purchase-orders/{id}
     * Retrieve a Purchase Order by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $po = $this->purchaseOrderService->getPurchaseOrder($id);
            return response()->json($po->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Purchase order not found.'], 404);
        }
    }

    /**
     * POST /api/v1/procurement/purchase-orders/{id}/items
     * Add an item to a draft Purchase Order.
     */
    public function addItem(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'quantity'    => ['nullable', 'numeric', 'min:0'],
            'unit'        => ['nullable', 'string', 'max:50'],
            'unit_price'  => ['nullable', 'numeric', 'min:0'],
            'total_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $item = $this->purchaseOrderService->addItem($id, $validated);
            return response()->json($item->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-orders/{id}/submit
     * Submit a Purchase Order for approval.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $po = $this->purchaseOrderService->submitPurchaseOrder($id, $actorId, $tenantContext->slug);
            return response()->json($po->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-orders/{id}/approve
     * Approve a submitted Purchase Order.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('po.approve')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $po = $this->purchaseOrderService->approvePurchaseOrder($id, $actorId, $tenantContext->slug);
            return response()->json($po->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-orders/{id}/issue
     * Issue an approved Purchase Order.
     */
    public function issue(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('po.approve')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $po = $this->purchaseOrderService->issuePurchaseOrder($id, $actorId, $tenantContext->slug);
            return response()->json($po->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-orders/{id}/cancel
     * Cancel a Purchase Order.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $po = $this->purchaseOrderService->cancelPurchaseOrder($id, $actorId, $tenantContext->slug);
            return response()->json($po->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/procurement/purchase-orders
     * Paginated list of PurchaseOrders in the active tenant schema.
     *
     * WEB-001 PHASE 4 — Purchase Orders List API
     */
    public function list(Request $request): JsonResponse
    {
        $result = $this->purchaseOrderService->listPurchaseOrders();

        return response()->json([
            'data' => array_map(fn ($po) => $po->toArray(), $result->items()),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ], 200);
    }
}
