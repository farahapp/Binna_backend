<?php

declare(strict_types=1);

namespace App\CostControl;

use App\Approval\ApprovalService;
use App\Audit\AuditService;
use App\CostControl\Events\ActualCostRecorded;
use App\CostControl\Events\BudgetApproved;
use App\CostControl\Events\BudgetExceeded;
use App\CostControl\Events\CommitmentCreated;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * CostControlService — Application/Service Layer for Cost Control module.
 *
 * Architectural constraints (TASK-006B / A1 MOD-07):
 *  - Implements Public Contracts: getBudget, getCommitmentBalance, recordActualCost, getCostPosition
 *  - Dispatches Domain Events: BudgetApproved, BudgetExceeded, CommitmentCreated, ActualCostRecorded
 *  - Integrates with AuditService per established conventions
 *  - Integrates with ApprovalService per OD-007 (Shared Approval Service)
 *  - MUST NOT depend on Finance, Accounting, or Forecasting
 *  - Tenant context resolved by caller — never cross-tenant access
 *  - Cost Control owns Budget (OD-CC-001 RESOLVED)
 *
 * TASK-006B — Cost Control Application/Service Layer
 */
class CostControlService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts (A4 §4.4)
    // -------------------------------------------------------------------------

    /**
     * getBudget — returns Budget for a given project.
     * Throws InvalidArgumentException if not found.
     */
    public function getBudget(string $projectId): Budget
    {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        $budget = Budget::where('project_id', $projectId)->first();

        if ($budget === null) {
            throw new InvalidArgumentException("Budget not found for project: {$projectId}");
        }

        return $budget;
    }

    /**
     * getCommitmentBalance — returns total committed amount for a project/costCode.
     * Returns 0.0 if no commitments found.
     */
    public function getCommitmentBalance(string $projectId, string|null $costCodeId = null): float
    {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        $budget = Budget::where('project_id', $projectId)->first();

        if ($budget === null) {
            return 0.0;
        }

        $query = Commitment::where('budget_id', $budget->id)
            ->where('status', 'approved');

        if ($costCodeId !== null) {
            $query->where('cost_code_id', $costCodeId);
        }

        return (float) $query->sum('amount');
    }

    /**
     * getCostPosition — returns budget vs committed vs actual for a project.
     * Returns zeros if no budget found.
     */
    public function getCostPosition(string $projectId): array
    {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        $budget = Budget::where('project_id', $projectId)->first();

        if ($budget === null) {
            return [
                'budget_amount'    => 0.0,
                'committed_amount' => 0.0,
                'actual_amount'    => 0.0,
                'remaining'        => 0.0,
            ];
        }

        $committed = (float) Commitment::where('budget_id', $budget->id)
            ->where('status', 'approved')
            ->sum('amount');

        $actual = (float) ActualCost::where('budget_id', $budget->id)
            ->sum('amount');

        $budgetAmount = (float) $budget->total_amount;

        return [
            'budget_amount'    => $budgetAmount,
            'committed_amount' => $committed,
            'actual_amount'    => $actual,
            'remaining'        => $budgetAmount - $committed - $actual,
        ];
    }

    // -------------------------------------------------------------------------
    // Application Operations
    // -------------------------------------------------------------------------

    /**
     * createBudget — creates a new Budget in draft state.
     */
    public function createBudget(
        string $tenantSlug,
        string $name,
        float $totalAmount,
        string|null $projectId = null,
        string|null $currency = 'SAR',
        int|null $fiscalYear = null,
        string|null $description = null,
        string|null $actorId = null,
    ): Budget {
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenantSlug must not be empty');
        }
        if (empty($name)) {
            throw new InvalidArgumentException('name must not be empty');
        }
        if ($totalAmount < 0) {
            throw new InvalidArgumentException('totalAmount must not be negative');
        }

        $budget = Budget::create([
            'tenant_slug'  => $tenantSlug,
            'name'         => $name,
            'total_amount' => $totalAmount,
            'project_id'   => $projectId,
            'currency'     => $currency ?? 'SAR',
            'fiscal_year'  => $fiscalYear,
            'description'  => $description,
            'status'       => 'draft',
        ]);

        $this->auditService->logEvent('cost_control.budget.created', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => Budget::class,
            'subject_id'   => $budget->id,
            'after'        => ['name' => $name, 'total_amount' => $totalAmount, 'status' => 'draft'],
        ]);

        return $budget;
    }

    /**
     * approveBudget — submits a Budget for approval via the shared ApprovalService.
     * Transitions budget to 'active' upon approval grant.
     */
    public function approveBudget(
        string $budgetId,
        string $requestedBy,
        string $tenantSlug,
        string|null $notes = null,
    ): Budget {
        if (empty($budgetId)) {
            throw new InvalidArgumentException('budgetId must not be empty');
        }

        $budget = Budget::find($budgetId);

        if ($budget === null) {
            throw new InvalidArgumentException("Budget not found: {$budgetId}");
        }

        if (!$budget->isDraft()) {
            throw new InvalidArgumentException("Budget [{$budgetId}] is not in draft state — cannot request approval.");
        }

        // Submit to shared Approval Service (OD-007)
        $approvalRequest = $this->approvalService->requestApproval(
            approvableType: Budget::class,
            approvableId:   $budgetId,
            requestedBy:    $requestedBy,
            tenantSlug:     $tenantSlug,
            notes:          $notes,
        );

        // Approve immediately (SC-APPROVAL-1: approver identity rule unresolved — follows existing convention)
        $this->approvalService->approve(
            approvalRequestId: $approvalRequest->id,
            actorId:           $requestedBy,
        );

        // Transition budget to active
        $before = ['status' => $budget->status];
        $budget->status = 'active';
        $budget->save();

        // Dispatch domain event
        Event::dispatch(new BudgetApproved(
            budgetId:   $budget->id,
            tenantSlug: $tenantSlug,
            occurredAt: new \DateTimeImmutable(),
        ));

        // Audit
        $this->auditService->logEvent('cost_control.budget.approved', $requestedBy, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => Budget::class,
            'subject_id'   => $budget->id,
            'before'       => $before,
            'after'        => ['status' => 'active'],
        ]);

        return $budget->fresh();
    }

    /**
     * recordCommitment — records a financial commitment against a Budget.
     * Dispatches BudgetExceeded if total committed exceeds budget amount.
     */
    public function recordCommitment(
        string $budgetId,
        float $amount,
        string $tenantSlug,
        string|null $costCodeId = null,
        string|null $description = null,
        string|null $contractId = null,
        string|null $committedDate = null,
        string|null $actorId = null,
    ): Commitment {
        if (empty($budgetId)) {
            throw new InvalidArgumentException('budgetId must not be empty');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount must be positive');
        }

        $budget = Budget::find($budgetId);

        if ($budget === null) {
            throw new InvalidArgumentException("Budget not found: {$budgetId}");
        }

        if ($budget->status !== 'active') {
            throw new InvalidArgumentException("Commitments can only be recorded against an active budget. Budget [{$budgetId}] is currently '{$budget->status}'.");
        }

        $commitment = Commitment::create([
            'budget_id'      => $budgetId,
            'cost_code_id'   => $costCodeId,
            'description'    => $description,
            'amount'         => $amount,
            'status'         => 'approved',
            'contract_id'    => $contractId,
            'committed_date' => $committedDate,
        ]);

        // Dispatch CommitmentCreated event
        Event::dispatch(new CommitmentCreated(
            commitmentId: $commitment->id,
            budgetId:     $budgetId,
            tenantSlug:   $tenantSlug,
            occurredAt:   new \DateTimeImmutable(),
        ));

        // Check if budget is exceeded
        $totalCommitted = (float) Commitment::where('budget_id', $budgetId)
            ->where('status', 'approved')
            ->sum('amount');

        if ($totalCommitted > (float) $budget->total_amount) {
            Event::dispatch(new BudgetExceeded(
                budgetId:        $budgetId,
                tenantSlug:      $tenantSlug,
                budgetAmount:    (float) $budget->total_amount,
                committedAmount: $totalCommitted,
                occurredAt:      new \DateTimeImmutable(),
            ));
        }

        // Audit
        $this->auditService->logEvent('cost_control.commitment.created', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => Commitment::class,
            'subject_id'   => $commitment->id,
            'after'        => ['budget_id' => $budgetId, 'amount' => $amount],
        ]);

        return $commitment;
    }

    /**
     * recordActualCost — records an actual expenditure against a Budget.
     * Dispatches ActualCostRecorded event.
     */
    public function recordActualCost(
        string $budgetId,
        float $amount,
        string $tenantSlug,
        string|null $costCodeId = null,
        string|null $description = null,
        string|null $invoiceId = null,
        string|null $costDate = null,
        string|null $actorId = null,
    ): ActualCost {
        if (empty($budgetId)) {
            throw new InvalidArgumentException('budgetId must not be empty');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount must be positive');
        }

        $budget = Budget::find($budgetId);

        if ($budget === null) {
            throw new InvalidArgumentException("Budget not found: {$budgetId}");
        }

        $actualCost = ActualCost::create([
            'budget_id'    => $budgetId,
            'cost_code_id' => $costCodeId,
            'description'  => $description,
            'amount'       => $amount,
            'invoice_id'   => $invoiceId,
            'cost_date'    => $costDate,
        ]);

        // Dispatch ActualCostRecorded event
        Event::dispatch(new ActualCostRecorded(
            actualCostId: $actualCost->id,
            budgetId:     $budgetId,
            tenantSlug:   $tenantSlug,
            occurredAt:   new \DateTimeImmutable(),
        ));

        // Audit
        $this->auditService->logEvent('cost_control.actual_cost.recorded', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => ActualCost::class,
            'subject_id'   => $actualCost->id,
            'after'        => ['budget_id' => $budgetId, 'amount' => $amount],
        ]);

        return $actualCost;
    }
}
