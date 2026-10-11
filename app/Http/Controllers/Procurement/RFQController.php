<?php

declare(strict_types=1);

namespace App\Http\Controllers\Procurement;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Procurement\RFQService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RFQController — CAP-062 HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-08 / TASK-007C):
 *  - Delegates ALL business logic to RFQService.
 *  - Does NOT contain RFQ lifecycle logic or quotation comparison logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *
 * TASK-007C — Procurement HTTP API Layer (CAP-062)
 */
class RFQController extends Controller
{
    public function __construct(
        private readonly RFQService $rfqService,
    ) {}

    /**
     * POST /api/v1/procurement/rfqs
     * Create a new RFQ in draft status.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'                => ['required', 'string', 'max:255'],
            'purchase_request_id'  => ['nullable', 'string', 'uuid'],
            'description'          => ['nullable', 'string'],
            'deadline'             => ['nullable', 'date'],
        ]);

        $actorId = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $rfq = $this->rfqService->createRFQ($validated, $actorId);
            return response()->json($rfq->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/procurement/rfqs/{id}
     * Retrieve an RFQ by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $rfq = $this->rfqService->getRFQ($id);
            return response()->json($rfq->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'RFQ not found.'], 404);
        }
    }

    /**
     * PATCH /api/v1/procurement/rfqs/{id}/send
     * Send an RFQ to suppliers.
     */
    public function send(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $rfq = $this->rfqService->sendRFQ($id, $actorId, $tenantContext->slug);
            return response()->json($rfq->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/rfqs/{id}/close
     * Close an RFQ.
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $rfq = $this->rfqService->closeRFQ($id, $actorId, $tenantContext->slug);
            return response()->json($rfq->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/procurement/rfqs/{id}/quotations
     * Submit a supplier quotation for an RFQ.
     */
    public function submitQuotation(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id'  => ['required', 'string', 'uuid'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'currency'     => ['nullable', 'string', 'max:10'],
            'notes'        => ['nullable', 'string'],
            'valid_until'  => ['nullable', 'date'],
        ]);

        $actorId = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $quotation = $this->rfqService->submitQuotation($id, $validated, $actorId);
            return response()->json($quotation->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/procurement/rfqs/{id}/quotations/compare
     * Compare quotations for an RFQ (sorted by amount).
     */
    public function compareQuotations(Request $request, string $id): JsonResponse
    {
        try {
            $quotations = $this->rfqService->compareQuotations($id);
            return response()->json($quotations->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/procurement/rfqs/{rfqId}/quotations/{quotationId}/select
     * Select a quotation as the winner.
     */
    public function selectQuotation(Request $request, string $rfqId, string $quotationId): JsonResponse
    {
        if (! $request->user()?->hasPermission('pr.approve')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $quotation = $this->rfqService->selectQuotation($quotationId, $actorId, $tenantContext->slug);
            return response()->json($quotation->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
