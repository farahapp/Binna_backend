<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Procurement\PurchaseRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PurchaseRequestController — CAP-061 HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-08 / TASK-007C):
 *  - Delegates ALL business logic to PurchaseRequestService.
 *  - Does NOT contain procurement state transitions or approval logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *
 * TASK-007C — Procurement HTTP API Layer (CAP-061)
 */
class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $purchaseRequestService,
    ) {}

    /**
     * POST /api/v1/procurement/purchase-requests
     * Create a new Purchase Request in draft status.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'project_id'    => ['nullable', 'string', 'uuid'],
            'required_date' => ['nullable', 'date'],
        ]);

        $actorId = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;
        $validated['requested_by'] = $actorId;

        try {
            $pr = $this->purchaseRequestService->createPurchaseRequest($validated, $actorId);
            return response()->json($pr->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/procurement/purchase-requests/{id}
     * Retrieve a Purchase Request by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $pr = $this->purchaseRequestService->getPurchaseRequest($id);
            return response()->json($pr->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Purchase request not found.'], 404);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-requests/{id}/submit
     * Submit a Purchase Request for approval.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $pr = $this->purchaseRequestService->submitPurchaseRequest($id, $actorId, $tenantContext->slug);
            return response()->json($pr->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-requests/{id}/approve
     * Approve a submitted Purchase Request.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('pr.approve')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $pr = $this->purchaseRequestService->approvePurchaseRequest($id, $actorId, $tenantContext->slug);
            return response()->json($pr->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-requests/{id}/reject
     * Reject a submitted Purchase Request.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('pr.approve')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $pr = $this->purchaseRequestService->rejectPurchaseRequest(
                $id,
                $actorId,
                $tenantContext->slug,
                $validated['reason'] ?? null
            );
            return response()->json($pr->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/purchase-requests/{id}/cancel
     * Cancel a Purchase Request.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $pr = $this->purchaseRequestService->cancelPurchaseRequest($id, $actorId, $tenantContext->slug);
            return response()->json($pr->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
