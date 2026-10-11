<?php

declare(strict_types=1);

namespace App\Http\Controllers\Subcontracts;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\Subcontracts\Services\SubcontractAgreementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SubcontractAgreementController — CAP-080 HTTP API Layer (Agreement).
 *
 * Architectural constraints (A1 MOD-10 / TASK-009C):
 *  - Delegates ALL business logic to SubcontractAgreementService.
 *  - Does NOT contain lifecycle rules, approval logic, or retention logic.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum.
 *  - Controllers are thin: validate → delegate → respond.
 *
 * TASK-009C — Subcontract HTTP API Layer (CAP-080)
 */
class SubcontractAgreementController extends Controller
{
    public function __construct(
        private readonly SubcontractAgreementService $agreementService,
    ) {}

    /**
     * GET /api/v1/subcontracts/agreements
     * Return a paginated list of SubcontractAgreements for the current tenant.
     *
     * WEB-001P — OI-5 resolution.
     * Tenant isolation: physical (Schema-per-Tenant / OD-001) — no slug filter needed.
     */
    public function listAgreements(Request $request): JsonResponse
    {
        $perPage   = 20;
        $paginator = $this->agreementService->listAgreements($perPage);

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
     * POST /api/v1/subcontracts/agreements
     * Create a new SubcontractAgreement in draft status.
     */
    public function createAgreement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agreement_number'     => ['required', 'string', 'max:100'],
            'subcontractor_name'   => ['required', 'string', 'max:255'],
            'subcontractor_id'     => ['nullable', 'string', 'uuid'],
            'project_id'           => ['nullable', 'string', 'uuid'],
            'title'                => ['required', 'string', 'max:255'],
            'scope_summary'        => ['nullable', 'string'],
            'contract_value'       => ['required', 'numeric', 'min:0'],
            'retention_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency'             => ['nullable', 'string', 'max:10'],
            'start_date'           => ['nullable', 'date'],
            'end_date'             => ['nullable', 'date'],
            'notes'                => ['nullable', 'string'],
        ]);

        $actorId = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        try {
            $agreement = $this->agreementService->createAgreement($validated, $actorId);
            return response()->json($agreement->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/subcontracts/agreements/{id}
     * Retrieve a SubcontractAgreement by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $agreement = $this->agreementService->getAgreement($id);
            return response()->json($agreement->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Subcontract agreement not found.'], 404);
        }
    }

    /**
     * PATCH /api/v1/subcontracts/agreements/{id}/submit
     * Submit a SubcontractAgreement for approval.
     */
    public function submitForApproval(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.submit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $agreement = $this->agreementService->submitForApproval($id, $actorId, $tenantContext->slug);
            return response()->json($agreement->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/subcontracts/agreements/{id}/activate
     * Activate a SubcontractAgreement.
     */
    public function activateAgreement(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.activate')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $agreement = $this->agreementService->activateAgreement($id, $actorId, $tenantContext->slug);
            return response()->json($agreement->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/subcontracts/agreements/{id}/complete
     * Complete a SubcontractAgreement.
     */
    public function completeAgreement(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.complete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $agreement = $this->agreementService->completeAgreement($id, $actorId, $tenantContext->slug);
            return response()->json($agreement->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/subcontracts/agreements/{id}/cancel
     * Cancel a SubcontractAgreement.
     */
    public function cancelAgreement(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('subcontract.cancel')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $actorId = (string) $request->user()->id;
        $tenantContext = app(TenantContext::class);

        try {
            $agreement = $this->agreementService->cancelAgreement($id, $actorId, $tenantContext->slug);
            return response()->json($agreement->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/subcontracts/agreements/{id}/value
     * Retrieve the subcontract value summary.
     */
    public function getSubcontractValue(Request $request, string $id): JsonResponse
    {
        try {
            $value = $this->agreementService->getSubcontractValue($id);
            return response()->json($value, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/v1/subcontracts/agreements/{id}/scopes
     * Add a scope item to a SubcontractAgreement.
     */
    public function addScope(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'scope_item_number' => ['required', 'string', 'max:100'],
            'description'       => ['required', 'string', 'max:500'],
            'unit'              => ['nullable', 'string', 'max:50'],
            'quantity'          => ['nullable', 'numeric', 'min:0'],
            'unit_rate'         => ['nullable', 'numeric', 'min:0'],
            'total_amount'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $actorId = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantContext = app(TenantContext::class);

        try {
            $scope = $this->agreementService->addScope($id, $validated, $tenantContext->slug, $actorId);
            return response()->json($scope->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
