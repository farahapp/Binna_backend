<?php

declare(strict_types=1);

namespace App\Http\Controllers\Commercial;

use App\Commercial\CommercialService;
use App\Commercial\CommercialVisibilityService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\IAM\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CommercialController — Commercial HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-05 / TASK-004C):
 *  - Delegates ALL business logic to CommercialService.
 *  - Does NOT access Commercial models directly.
 *  - Does NOT depend on Finance, Accounting, Invoicing, Receivables,
 *    Procurement, HR, Equipment, or Projects.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum (TASK-001B).
 *
 * CAP-012 — Field-level visibility enforced via CommercialVisibilityService.
 *   Customer / Owner roles MUST NOT receive internal purchase prices,
 *   profit margins, or internal costs. Backend is the sole authority.
 *
 * TASK-004C — Commercial HTTP API Layer
 */
class CommercialController extends Controller
{
    public function __construct(
        private readonly CommercialService $commercialService,
        private readonly CommercialVisibilityService $visibilityService,
    ) {}

    // =========================================================================
    // PUBLIC CONTRACTS (B1)
    // =========================================================================

    /**
     * GET /api/v1/commercial/contracts/{id}
     * Get a single Contract by ID.
     *
     * CAP-012: Internal fields (purchase prices, margins, costs) are stripped
     * for Customer / Owner roles. Backend is the sole authority.
     */
    public function getContract(Request $request, string $id): JsonResponse
    {
        try {
            $contract = $this->commercialService->getContract($id);
            /** @var User|null $user */
            $user = $request->user();
            $data = $this->visibilityService->filterFields($contract->toArray(), $user);
            return response()->json($data, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }
    }

    /**
     * GET /api/v1/commercial/contracts/{id}/value
     * Get the financial value breakdown of a Contract.
     *
     * CAP-012: Internal cost fields stripped for Customer / Owner roles.
     */
    public function getContractValue(Request $request, string $id): JsonResponse
    {
        try {
            $value = $this->commercialService->getContractValue($id);
            /** @var User|null $user */
            $user = $request->user();
            $data = $this->visibilityService->filterFields($value, $user);
            return response()->json($data, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }
    }

    /**
     * GET /api/v1/commercial/boqs/{id}
     * Get a BOQ by ID.
     *
     * CAP-012: Internal fields stripped for Customer / Owner roles.
     */
    public function getBOQ(Request $request, string $id): JsonResponse
    {
        try {
            $boq = $this->commercialService->getBOQ($id);
            /** @var User|null $user */
            $user = $request->user();
            $data = $this->visibilityService->filterFields($boq->toArray(), $user);
            return response()->json($data, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'BOQ not found.'], 404);
        }
    }

    // =========================================================================
    // CREATION OPERATIONS (B2-UNIT1)
    // =========================================================================

    /**
     * POST /api/v1/commercial/tenders
     * Create a new Tender.
     */
    public function createTender(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'client_id'   => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'due_date'    => ['nullable', 'date'],
        ]);

        $actorId = $request->user()?->id;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $tender = $this->commercialService->createTender($validated, $actorId);
            return response()->json($tender->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/commercial/boqs
     * Create a new BOQ.
     */
    public function createBOQ(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'      => ['required', 'string', 'max:255'],
            'type'       => ['required', 'string', 'in:tender_boq,contract_boq,variation_boq'],
            'tender_id'  => ['nullable', 'string'],
            'sections'   => ['nullable', 'array'],
        ]);

        $actorId = $request->user()?->id;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $boq = $this->commercialService->createBOQ($validated, $actorId);
            return response()->json($boq->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/commercial/quotations
     * Create a new Quotation.
     */
    public function createQuotation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quotation_number' => ['required', 'string', 'max:100'],
            'client_id'        => ['required', 'string'],
            'tender_id'        => ['nullable', 'string'],
            'boq_id'           => ['nullable', 'string'],
            'total_value'      => ['nullable', 'numeric'],
            'title'            => ['nullable', 'string', 'max:255'],
            'quotation_date'   => ['nullable', 'date'],
            'validity_date'    => ['nullable', 'date'],
        ]);

        $actorId = $request->user()?->id;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $quotation = $this->commercialService->createQuotation($validated, $actorId);
            return response()->json($quotation->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/commercial/contracts
     * Create a new Contract.
     */
    public function createContract(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_number' => ['required', 'string', 'max:100'],
            'client_id'       => ['required', 'string'],
            'contract_value'  => ['required', 'numeric'],
            'start_date'      => ['nullable', 'date'],
            'end_date'        => ['nullable', 'date'],
        ]);

        $actorId = $request->user()?->id;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $contract = $this->commercialService->createContract($validated, $actorId);
            return response()->json($contract->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/commercial/variations
     * Create a new ContractVariation (CAP-033).
     */
    public function createVariation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id'       => ['required', 'string'],
            'reason'            => ['required', 'string'],
            'variation_number'  => ['nullable', 'string', 'max:50'],
            'scope_description' => ['nullable', 'string'],
            'value_change'      => ['nullable', 'numeric'],
            'notes'             => ['nullable', 'string'],
        ]);

        $actorId = $request->user()?->id;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $variation = $this->commercialService->createVariation($validated, $actorId);
            return response()->json($variation->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/commercial/variations/{id}
     * Retrieve a ContractVariation by ID (CAP-033).
     *
     * CAP-012: Internal fields stripped for Customer / Owner roles.
     */
    public function getVariation(Request $request, string $id): JsonResponse
    {
        try {
            $variation = $this->commercialService->getVariation($id);
            /** @var User|null $user */
            $user = $request->user();
            $data = $this->visibilityService->filterFields($variation->toArray(), $user);
            return response()->json($data, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Variation not found.'], 404);
        }
    }

    /**
     * GET /api/v1/commercial/contracts/{id}/variations
     * List all ContractVariations for a Contract (CAP-033).
     *
     * CAP-012: Internal fields stripped for Customer / Owner roles.
     */
    public function listVariations(Request $request, string $id): JsonResponse
    {
        try {
            $variations = $this->commercialService->listVariations($id);
            /** @var User|null $user */
            $user = $request->user();
            $data = $this->visibilityService->filterCollection($variations->toArray(), $user);
            return response()->json($data, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Contract not found.'], 404);
        }
    }

    // =========================================================================
    // LIFECYCLE TRANSITIONS (B2-UNIT2)
    // =========================================================================

    /**
     * PATCH /api/v1/commercial/tenders/{id}/award
     * Award a Tender.
     */
    public function awardTender(Request $request, string $id): JsonResponse
    {
        $actorId = $request->user()?->id;
        try {
            $tender = $this->commercialService->awardTender($id, $actorId);
            return response()->json($tender->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/v1/commercial/contracts/{id}/sign
     * Sign a Contract (transition to ACTIVE).
     */
    public function signContract(Request $request, string $id): JsonResponse
    {
        $actorId = $request->user()?->id;
        try {
            $contract = $this->commercialService->signContract($id, $actorId);
            return response()->json($contract->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/v1/commercial/quotations/{id}/accept
     * Accept a Quotation.
     */
    public function acceptQuotation(Request $request, string $id): JsonResponse
    {
        $actorId = $request->user()?->id;
        try {
            $quotation = $this->commercialService->acceptQuotation($id, $actorId);
            return response()->json($quotation->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/v1/commercial/variations/{id}/approve
     * Approve a ContractVariation (CAP-033).
     */
    public function approveVariation(Request $request, string $id): JsonResponse
    {
        $actorId = $request->user()?->id;
        try {
            $variation = $this->commercialService->approveVariation($id, $actorId);
            return response()->json($variation->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/v1/commercial/variations/{id}/reject
     * Reject a ContractVariation (CAP-033).
     */
    public function rejectVariation(Request $request, string $id): JsonResponse
    {
        $actorId = $request->user()?->id;
        try {
            $variation = $this->commercialService->rejectVariation($id, $actorId);
            return response()->json($variation->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/v1/commercial/quotations/{id}/submit-for-approval
     * Submit a Quotation for approval.
     */
    public function submitQuotationForApproval(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'requested_by' => ['required', 'string'],
        ]);

        $actorId = $request->user()?->id;
        try {
            $result = $this->commercialService->submitQuotationForApproval(
                $id,
                $validated['requested_by'],
                $actorId,
            );
            return response()->json([
                'quotation'        => $result['quotation']->toArray(),
                'approval_request' => $result['approval_request']->toArray(),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/v1/commercial/contracts/{id}/submit-for-approval
     * Submit a Contract for approval.
     */
    public function submitContractForApproval(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'requested_by' => ['required', 'string'],
        ]);

        $actorId = $request->user()?->id;
        try {
            $result = $this->commercialService->submitContractForApproval(
                $id,
                $validated['requested_by'],
                $actorId,
            );
            return response()->json([
                'contract'         => $result['contract']->toArray(),
                'approval_request' => $result['approval_request']->toArray(),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    // =========================================================================
    // LIST ENDPOINTS — WEB-001 PHASE 4
    // =========================================================================

    /**
     * GET /api/v1/commercial/tenders
     * Paginated list of Tenders in the active tenant schema.
     *
     * WEB-001 PHASE 4 — Tenders List API
     */
    public function listTenders(Request $request): JsonResponse
    {
        $result = $this->commercialService->listTenders();

        return response()->json([
            'data' => array_map(fn ($t) => $t->toArray(), $result->items()),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/v1/commercial/contracts
     * Paginated list of Contracts in the active tenant schema.
     *
     * CAP-012: Internal fields stripped for Customer / Owner roles.
     * WEB-001 PHASE 4 — Contracts List API
     */
    public function listContracts(Request $request): JsonResponse
    {
        $result = $this->commercialService->listContracts();
        /** @var \App\IAM\User|null $user */
        $user = $request->user();

        return response()->json([
            'data' => array_map(
                fn ($c) => $this->visibilityService->filterFields($c->toArray(), $user),
                $result->items()
            ),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/v1/commercial/quotations
     * Paginated list of Quotations in the active tenant schema.
     *
     * WEB-001 PHASE 4 — Quotations List API
     */
    public function listQuotations(Request $request): JsonResponse
    {
        $result = $this->commercialService->listQuotations();

        return response()->json([
            'data' => array_map(fn ($q) => $q->toArray(), $result->items()),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/v1/commercial/quotations/{id}
     * Get a single Quotation by ID.
     *
     * WEB-001 PHASE 4 — Quotation Show API
     */
    public function getQuotation(Request $request, string $id): JsonResponse
    {
        try {
            $quotation = $this->commercialService->getQuotation($id);
            return response()->json($quotation->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }
    }
}
