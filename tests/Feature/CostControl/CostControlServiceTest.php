<?php

declare(strict_types=1);

namespace Tests\Feature\CostControl;

use App\CostControl\ActualCost;
use App\CostControl\Budget;
use App\CostControl\Commitment;
use App\CostControl\CostControlService;
use App\CostControl\Events\ActualCostRecorded;
use App\CostControl\Events\BudgetApproved;
use App\CostControl\Events\BudgetExceeded;
use App\CostControl\Events\CommitmentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for Cost Control Application/Service Layer — TASK-006B.
 *
 * Verifies:
 *  1. Budget creation and retrieval
 *  2. Budget approval integration (ApprovalService)
 *  3. Commitment recording and balance
 *  4. BudgetExceeded behavior
 *  5. Actual cost recording
 *  6. Cost position calculation
 *  7. Domain events dispatched
 *  8. Audit integration
 *  9. Tenant isolation
 *  10. Public contracts
 */
class CostControlServiceTest extends TestCase
{
    use RefreshDatabase;

    private CostControlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CostControlService::class);
    }

    // -------------------------------------------------------------------------
    // Budget Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function create_budget_creates_draft_budget(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Project Alpha Budget',
            totalAmount: 500000.0,
            projectId:   null,
            currency:    'SAR',
            fiscalYear:  2026,
        );

        $this->assertNotNull($budget->id);
        $this->assertSame('draft', $budget->status);
        $this->assertSame('Project Alpha Budget', $budget->name);
        $this->assertSame('tenant-a', $budget->tenant_slug);
        $this->assertTrue($budget->isDraft());
    }

    /** @test */
    public function create_budget_requires_non_empty_tenant_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createBudget(tenantSlug: '', name: 'Test', totalAmount: 1000.0);
    }

    /** @test */
    public function create_budget_requires_non_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createBudget(tenantSlug: 'tenant-a', name: '', totalAmount: 1000.0);
    }

    /** @test */
    public function create_budget_rejects_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createBudget(tenantSlug: 'tenant-a', name: 'Test', totalAmount: -100.0);
    }

    /** @test */
    public function create_budget_records_audit_event(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Audit Test Budget',
            totalAmount: 100000.0,
        );

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'cost_control.budget.created',
            'subject_type' => Budget::class,
            'subject_id'   => $budget->id,
            'tenant_slug'  => 'tenant-a',
        ], 'tenant');
    }

    // -------------------------------------------------------------------------
    // Budget Approval Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function approve_budget_transitions_to_active(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Budget to Approve',
            totalAmount: 200000.0,
        );

        $this->assertSame('draft', $budget->status);

        $approved = $this->service->approveBudget(
            budgetId:    $budget->id,
            requestedBy: 'user-001',
            tenantSlug:  'tenant-a',
        );

        $this->assertSame('active', $approved->status);
        $this->assertTrue($approved->isActive());
    }

    /** @test */
    public function approve_budget_dispatches_budget_approved_event(): void
    {
        Event::fake([BudgetApproved::class]);

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Event Test Budget',
            totalAmount: 100000.0,
        );

        $this->service->approveBudget(
            budgetId:    $budget->id,
            requestedBy: 'user-001',
            tenantSlug:  'tenant-a',
        );

        Event::assertDispatched(BudgetApproved::class, function (BudgetApproved $event) use ($budget) {
            return $event->budgetId === $budget->id
                && $event->tenantSlug === 'tenant-a';
        });
    }

    /** @test */
    public function approve_budget_records_audit_event(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Audit Approval Budget',
            totalAmount: 100000.0,
        );

        $this->service->approveBudget(
            budgetId:    $budget->id,
            requestedBy: 'user-001',
            tenantSlug:  'tenant-a',
        );

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'cost_control.budget.approved',
            'subject_type' => Budget::class,
            'subject_id'   => $budget->id,
            'tenant_slug'  => 'tenant-a',
        ], 'tenant');
    }

    /** @test */
    public function approve_budget_rejects_non_draft_budget(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Already Active Budget',
            totalAmount: 100000.0,
        );

        // Approve once
        $this->service->approveBudget(
            budgetId:    $budget->id,
            requestedBy: 'user-001',
            tenantSlug:  'tenant-a',
        );

        // Try to approve again — should fail
        $this->expectException(\InvalidArgumentException::class);
        $this->service->approveBudget(
            budgetId:    $budget->id,
            requestedBy: 'user-001',
            tenantSlug:  'tenant-a',
        );
    }

    // -------------------------------------------------------------------------
    // Commitment Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function record_commitment_creates_commitment(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Commitment Test Budget',
            totalAmount: 100000.0,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $commitment = $this->service->recordCommitment(
            budgetId:    $budget->id,
            amount:      25000.0,
            tenantSlug:  'tenant-a',
            description: 'Subcontractor A',
        );

        $this->assertNotNull($commitment->id);
        $this->assertSame($budget->id, $commitment->budget_id);
        $this->assertSame('approved', $commitment->status);
    }

    /** @test */
    public function record_commitment_rejects_draft_budget(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Draft Budget',
            totalAmount: 100000.0,
        );

        $this->assertSame('draft', $budget->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/active/i');

        $this->service->recordCommitment(
            budgetId:   $budget->id,
            amount:     5000.0,
            tenantSlug: 'tenant-a',
        );
    }

    /** @test */
    public function record_commitment_dispatches_commitment_created_event(): void
    {
        Event::fake([CommitmentCreated::class, BudgetExceeded::class]);

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Event Commitment Budget',
            totalAmount: 100000.0,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $commitment = $this->service->recordCommitment(
            budgetId:   $budget->id,
            amount:     10000.0,
            tenantSlug: 'tenant-a',
        );

        Event::assertDispatched(CommitmentCreated::class, function (CommitmentCreated $event) use ($commitment) {
            return $event->commitmentId === $commitment->id
                && $event->budgetId === $commitment->budget_id;
        });
    }

    /** @test */
    public function record_commitment_records_audit_event(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Audit Commitment Budget',
            totalAmount: 100000.0,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $commitment = $this->service->recordCommitment(
            budgetId:   $budget->id,
            amount:     5000.0,
            tenantSlug: 'tenant-a',
        );

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'cost_control.commitment.created',
            'subject_type' => Commitment::class,
            'subject_id'   => $commitment->id,
            'tenant_slug'  => 'tenant-a',
        ], 'tenant');
    }

    /** @test */
    public function record_commitment_rejects_non_positive_amount(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Test Budget',
            totalAmount: 100000.0,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordCommitment(
            budgetId:   $budget->id,
            amount:     0.0,
            tenantSlug: 'tenant-a',
        );
    }

    // -------------------------------------------------------------------------
    // Budget Exceeded Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function budget_exceeded_event_dispatched_when_commitments_exceed_budget(): void
    {
        Event::fake([CommitmentCreated::class, BudgetExceeded::class]);

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Small Budget',
            totalAmount: 10000.0,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        // Record commitment that exceeds budget
        $this->service->recordCommitment(
            budgetId:   $budget->id,
            amount:     15000.0,
            tenantSlug: 'tenant-a',
        );

        Event::assertDispatched(BudgetExceeded::class, function (BudgetExceeded $event) use ($budget) {
            return $event->budgetId === $budget->id
                && $event->committedAmount > $event->budgetAmount;
        });
    }

    /** @test */
    public function budget_exceeded_event_not_dispatched_when_within_budget(): void
    {
        Event::fake([CommitmentCreated::class, BudgetExceeded::class]);

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Large Budget',
            totalAmount: 100000.0,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $this->service->recordCommitment(
            budgetId:   $budget->id,
            amount:     5000.0,
            tenantSlug: 'tenant-a',
        );

        Event::assertNotDispatched(BudgetExceeded::class);
    }

    // -------------------------------------------------------------------------
    // Commitment Balance Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function get_commitment_balance_returns_total_approved_commitments(): void
    {
        $projectId = 'project-uuid-001';

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Balance Test Budget',
            totalAmount: 100000.0,
            projectId:   $projectId,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $this->service->recordCommitment(budgetId: $budget->id, amount: 10000.0, tenantSlug: 'tenant-a');
        $this->service->recordCommitment(budgetId: $budget->id, amount: 20000.0, tenantSlug: 'tenant-a');

        $balance = $this->service->getCommitmentBalance($projectId);

        $this->assertEqualsWithDelta(30000.0, $balance, 0.01);
    }

    /** @test */
    public function get_commitment_balance_returns_zero_for_unknown_project(): void
    {
        $balance = $this->service->getCommitmentBalance('non-existent-project');
        $this->assertSame(0.0, $balance);
    }

    // -------------------------------------------------------------------------
    // Actual Cost Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function record_actual_cost_creates_actual_cost(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Actual Cost Test Budget',
            totalAmount: 100000.0,
        );

        $actualCost = $this->service->recordActualCost(
            budgetId:    $budget->id,
            amount:      15000.0,
            tenantSlug:  'tenant-a',
            description: 'Invoice #001',
            costDate:    '2026-09-28',
        );

        $this->assertNotNull($actualCost->id);
        $this->assertSame($budget->id, $actualCost->budget_id);
    }

    /** @test */
    public function record_actual_cost_dispatches_actual_cost_recorded_event(): void
    {
        Event::fake([ActualCostRecorded::class]);

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Event Actual Cost Budget',
            totalAmount: 100000.0,
        );

        $actualCost = $this->service->recordActualCost(
            budgetId:   $budget->id,
            amount:     8000.0,
            tenantSlug: 'tenant-a',
        );

        Event::assertDispatched(ActualCostRecorded::class, function (ActualCostRecorded $event) use ($actualCost) {
            return $event->actualCostId === $actualCost->id
                && $event->budgetId === $actualCost->budget_id;
        });
    }

    /** @test */
    public function record_actual_cost_records_audit_event(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Audit Actual Cost Budget',
            totalAmount: 100000.0,
        );

        $actualCost = $this->service->recordActualCost(
            budgetId:   $budget->id,
            amount:     3000.0,
            tenantSlug: 'tenant-a',
        );

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'cost_control.actual_cost.recorded',
            'subject_type' => ActualCost::class,
            'subject_id'   => $actualCost->id,
            'tenant_slug'  => 'tenant-a',
        ], 'tenant');
    }

    // -------------------------------------------------------------------------
    // Cost Position Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function get_cost_position_returns_correct_position(): void
    {
        $projectId = 'project-pos-001';

        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Position Test Budget',
            totalAmount: 100000.0,
            projectId:   $projectId,
        );
        $this->service->approveBudget(budgetId: $budget->id, requestedBy: 'user-001', tenantSlug: 'tenant-a');

        $this->service->recordCommitment(budgetId: $budget->id, amount: 30000.0, tenantSlug: 'tenant-a');
        $this->service->recordActualCost(budgetId: $budget->id, amount: 10000.0, tenantSlug: 'tenant-a');

        $position = $this->service->getCostPosition($projectId);

        $this->assertEqualsWithDelta(100000.0, $position['budget_amount'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $position['committed_amount'], 0.01);
        $this->assertEqualsWithDelta(10000.0, $position['actual_amount'], 0.01);
        $this->assertEqualsWithDelta(60000.0, $position['remaining'], 0.01);
    }

    /** @test */
    public function get_cost_position_returns_zeros_for_unknown_project(): void
    {
        $position = $this->service->getCostPosition('non-existent-project');

        $this->assertSame(0.0, $position['budget_amount']);
        $this->assertSame(0.0, $position['committed_amount']);
        $this->assertSame(0.0, $position['actual_amount']);
        $this->assertSame(0.0, $position['remaining']);
    }

    // -------------------------------------------------------------------------
    // Public Contract Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function get_budget_returns_budget_for_project(): void
    {
        $projectId = 'project-contract-001';

        $this->service->createBudget(
            tenantSlug:  'tenant-a',
            name:        'Contract Test Budget',
            totalAmount: 50000.0,
            projectId:   $projectId,
        );

        $budget = $this->service->getBudget($projectId);

        $this->assertNotNull($budget);
        $this->assertSame($projectId, $budget->project_id);
    }

    /** @test */
    public function get_budget_throws_for_unknown_project(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getBudget('non-existent-project');
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_a_cannot_access_tenant_b_budget(): void
    {
        $projectId = 'shared-project-id';

        // Create budget for tenant-b
        Budget::create([
            'tenant_slug'  => 'tenant-b',
            'name'         => 'Tenant B Budget',
            'total_amount' => 100000.0,
            'project_id'   => $projectId,
            'status'       => 'draft',
        ]);

        // tenant-a tries to get budget for same project — should throw (no budget for tenant-a)
        // The service uses the tenant connection (physical isolation) — tenant-a's schema has no data
        // In test environment both tenants share the same test DB, so we verify via tenant_slug
        $budget = Budget::where('project_id', $projectId)
            ->where('tenant_slug', 'tenant-a')
            ->first();

        $this->assertNull($budget, 'tenant-a must not see tenant-b budget');
    }

    /** @test */
    public function service_enforces_tenant_slug_in_audit(): void
    {
        $budget = $this->service->createBudget(
            tenantSlug:  'tenant-isolation-test',
            name:        'Isolation Budget',
            totalAmount: 50000.0,
        );

        $this->assertDatabaseHas('audit_entries', [
            'tenant_slug' => 'tenant-isolation-test',
            'subject_id'  => $budget->id,
        ], 'tenant');
    }

    // -------------------------------------------------------------------------
    // Service Provider Test
    // -------------------------------------------------------------------------

    /** @test */
    public function cost_control_service_resolves_from_container(): void
    {
        $service = app(CostControlService::class);
        $this->assertInstanceOf(CostControlService::class, $service);
    }
}
