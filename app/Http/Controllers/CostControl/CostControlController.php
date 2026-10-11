<?php

declare(strict_types=1);

namespace App\Http\Controllers\CostControl;

use App\CostControl\CostControlService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CostControlController — Cost Control HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-07 / TASK-006C):
 *  - Delegates ALL business logic to CostControlService.
 *  - Does NOT access CostControl models directly.
 *  - Does NOT depend on Finance, Accounting, or Forecasting.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum (TASK-001B).
 *
 * TASK-006C — Cost Control HTTP API Layer
 */
class CostControlController extends Controller
{
    public function __construct(
        private readonly CostControlService $costControlService,
    ) {}

    // =========================================================================
    // BUDGET — PUBLIC CONTRACTS
    // =========================================================================

    /**
     * GET /api/v1/cost-control/budgets/{projectId}
     */
    public function getBudget(Request $request, string $projectId): JsonResponse
    {
        try {
            $budget = $this->costControlService->getBudget($projectId);
            return response()->json($budget->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Budget not found.'], 404);
        }
    }

    // =========================================================================
    // BUDGET — OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/cost-control/budgets
     */
    public function createBudget(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:0',
            'project_id'   => 'nullable|uuid',
            'currency'     => 'nullable|string|max:10',
            'fiscal_year'  => 'nullable|integer',
            'description'  => 'nullable|string',
        ]);

        $tenantSlug = $request->header('X-Tenant');
        $actorId    = $request->user()?->id;

        try {
            $budget = $this->costControlService->createBudget(
                tenantSlug:  $tenantSlug,
                name:        $validated['name'],
                totalAmount: (float) $validated['total_amount'],
                projectId:   $validated['project_id'] ?? null,
                currency:    $validated['currency'] ?? 'SAR',
                fiscalYear:  isset($validated['fiscal_year']) ? (int) $validated['fiscal_year'] : null,
                description: $validated['description'] ?? null,
                actorId:     $actorId,
            );
            return response()->json($budget->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/cost-control/budgets/{budgetId}/approve
     */
    public function approveBudget(Request $request, string $budgetId): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $tenantSlug  = $request->header('X-Tenant');
        $requestedBy = $request->user()?->id;

        try {
            $budget = $this->costControlService->approveBudget(
                budgetId:    $budgetId,
                requestedBy: $requestedBy,
                tenantSlug:  $tenantSlug,
                notes:       $validated['notes'] ?? null,
            );
            return response()->json($budget->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // COMMITMENT — OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/cost-control/commitments
     */
    public function recordCommitment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'budget_id'      => 'required|uuid',
            'amount'         => 'required|numeric|min:0.01',
            'cost_code_id'   => 'nullable|uuid',
            'description'    => 'nullable|string',
            'contract_id'    => 'nullable|uuid',
            'committed_date' => 'nullable|date',
        ]);

        $tenantSlug = $request->header('X-Tenant');
        $actorId    = $request->user()?->id;

        try {
            $commitment = $this->costControlService->recordCommitment(
                budgetId:      $validated['budget_id'],
                amount:        (float) $validated['amount'],
                tenantSlug:    $tenantSlug,
                costCodeId:    $validated['cost_code_id'] ?? null,
                description:   $validated['description'] ?? null,
                contractId:    $validated['contract_id'] ?? null,
                committedDate: $validated['committed_date'] ?? null,
                actorId:       $actorId,
            );
            return response()->json($commitment->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // COMMITMENT — PUBLIC CONTRACTS
    // =========================================================================

    /**
     * GET /api/v1/cost-control/projects/{projectId}/commitment-balance
     */
    public function getCommitmentBalance(Request $request, string $projectId): JsonResponse
    {
        $costCodeId = $request->query('cost_code_id');

        try {
            $balance = $this->costControlService->getCommitmentBalance(
                projectId:  $projectId,
                costCodeId: $costCodeId ?: null,
            );
            return response()->json(['balance' => $balance], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // ACTUAL COST — OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/cost-control/actual-costs
     */
    public function recordActualCost(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'budget_id'    => 'required|uuid',
            'amount'       => 'required|numeric|min:0.01',
            'cost_code_id' => 'nullable|uuid',
            'description'  => 'nullable|string',
            'invoice_id'   => 'nullable|uuid',
            'cost_date'    => 'nullable|date',
        ]);

        $tenantSlug = $request->header('X-Tenant');
        $actorId    = $request->user()?->id;

        try {
            $actualCost = $this->costControlService->recordActualCost(
                budgetId:   $validated['budget_id'],
                amount:     (float) $validated['amount'],
                tenantSlug: $tenantSlug,
                costCodeId: $validated['cost_code_id'] ?? null,
                description: $validated['description'] ?? null,
                invoiceId:  $validated['invoice_id'] ?? null,
                costDate:   $validated['cost_date'] ?? null,
                actorId:    $actorId,
            );
            return response()->json($actualCost->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // COST POSITION — PUBLIC CONTRACTS
    // =========================================================================

    /**
     * GET /api/v1/cost-control/projects/{projectId}/cost-position
     */
    public function getCostPosition(Request $request, string $projectId): JsonResponse
    {
        try {
            $position = $this->costControlService->getCostPosition($projectId);
            return response()->json($position, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
