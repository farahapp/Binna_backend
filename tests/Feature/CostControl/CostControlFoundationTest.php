<?php

declare(strict_types=1);

namespace Tests\Feature\CostControl;

use App\CostControl\ActualCost;
use App\CostControl\Budget;
use App\CostControl\BudgetLine;
use App\CostControl\BudgetRevision;
use App\CostControl\Commitment;
use App\CostControl\CostCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Cost Control Domain/Data Foundation — TASK-006A.
 *
 * Verifies:
 *  1. All 6 Cost Control tables exist in tenant schema
 *  2. Cost Control migrations are in the correct path
 *  3. Model persistence works for all 6 entities
 *  4. Relationships work correctly
 *  5. Tenant isolation — no tenant_id column
 *  6. CostControlServiceProvider loads correctly
 */
class CostControlFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function cost_codes_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('cost_codes'),
            'cost_codes table must exist in the tenant schema'
        );
    }

    /** @test */
    public function budgets_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('budgets'),
            'budgets table must exist in the tenant schema'
        );
    }

    /** @test */
    public function budget_lines_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('budget_lines'),
            'budget_lines table must exist in the tenant schema'
        );
    }

    /** @test */
    public function budget_revisions_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('budget_revisions'),
            'budget_revisions table must exist in the tenant schema'
        );
    }

    /** @test */
    public function commitments_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('commitments'),
            'commitments table must exist in the tenant schema'
        );
    }

    /** @test */
    public function actual_costs_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('actual_costs'),
            'actual_costs table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function cost_control_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/cost_control/2026_09_28_100001_create_cost_codes_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/cost_control/2026_09_28_100002_create_budgets_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/cost_control/2026_09_28_100003_create_budget_lines_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/cost_control/2026_09_28_100004_create_budget_revisions_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/cost_control/2026_09_28_100005_create_commitments_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/cost_control/2026_09_28_100006_create_actual_costs_table.php')
        );
    }

    /** @test */
    public function cost_control_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_28_100001_create_cost_codes_table.php'),
            'Cost Control migrations must NOT be in the shared migration path'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function budgets_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('budgets', 'tenant_id'),
            'budgets table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function cost_codes_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('cost_codes', 'tenant_id'),
            'cost_codes table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function commitments_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('commitments', 'tenant_id'),
            'commitments table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function actual_costs_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('actual_costs', 'tenant_id'),
            'actual_costs table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Model Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function cost_code_can_be_created_and_retrieved(): void
    {
        $costCode = CostCode::create([
            'tenant_slug' => 'test-tenant',
            'code'        => 'CC-001',
            'name'        => 'Labour Costs',
            'is_active'   => true,
        ]);

        $this->assertNotNull($costCode->id);
        $this->assertSame('CC-001', $costCode->code);
        $this->assertSame('Labour Costs', $costCode->name);
        $this->assertTrue($costCode->is_active);

        $found = CostCode::find($costCode->id);
        $this->assertNotNull($found);
        $this->assertSame('CC-001', $found->code);
    }

    /** @test */
    public function budget_can_be_created_and_retrieved(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Project Alpha Budget 2026',
            'status'       => 'draft',
            'total_amount' => '500000.00',
            'currency'     => 'SAR',
            'fiscal_year'  => 2026,
        ]);

        $this->assertNotNull($budget->id);
        $this->assertSame('Project Alpha Budget 2026', $budget->name);
        $this->assertSame('draft', $budget->status);
        $this->assertTrue($budget->isDraft());
        $this->assertFalse($budget->isActive());

        $found = Budget::find($budget->id);
        $this->assertNotNull($found);
        $this->assertSame('draft', $found->status);
    }

    /** @test */
    public function budget_line_can_be_created_with_budget_relationship(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        $costCode = CostCode::create([
            'tenant_slug' => 'test-tenant',
            'code'        => 'CC-MAT',
            'name'        => 'Materials',
        ]);

        $line = BudgetLine::create([
            'budget_id'      => $budget->id,
            'cost_code_id'   => $costCode->id,
            'description'    => 'Steel materials',
            'planned_amount' => '25000.00',
        ]);

        $this->assertNotNull($line->id);
        $this->assertSame($budget->id, $line->budget_id);

        // Test relationship
        $this->assertSame($budget->id, $line->budget->id);
        $this->assertSame($costCode->id, $line->costCode->id);
    }

    /** @test */
    public function budget_revision_can_be_created_with_budget_relationship(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        $revision = BudgetRevision::create([
            'budget_id'       => $budget->id,
            'revision_number' => 1,
            'previous_amount' => '100000.00',
            'revised_amount'  => '120000.00',
            'reason'          => 'Scope increase',
        ]);

        $this->assertNotNull($revision->id);
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame($budget->id, $revision->budget->id);
    }

    /** @test */
    public function commitment_can_be_created_with_budget_relationship(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        $commitment = Commitment::create([
            'budget_id'   => $budget->id,
            'description' => 'Subcontractor commitment',
            'amount'      => '30000.00',
            'status'      => 'pending',
        ]);

        $this->assertNotNull($commitment->id);
        $this->assertSame('pending', $commitment->status);
        $this->assertTrue($commitment->isPending());
        $this->assertFalse($commitment->isApproved());
        $this->assertSame($budget->id, $commitment->budget->id);
    }

    /** @test */
    public function actual_cost_can_be_created_with_budget_relationship(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        $actualCost = ActualCost::create([
            'budget_id'   => $budget->id,
            'description' => 'Invoice payment',
            'amount'      => '15000.00',
            'cost_date'   => '2026-09-28',
        ]);

        $this->assertNotNull($actualCost->id);
        $this->assertSame($budget->id, $actualCost->budget->id);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function budget_has_many_lines(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        BudgetLine::create(['budget_id' => $budget->id, 'planned_amount' => '10000.00']);
        BudgetLine::create(['budget_id' => $budget->id, 'planned_amount' => '20000.00']);

        $this->assertCount(2, $budget->lines);
    }

    /** @test */
    public function budget_has_many_revisions(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        BudgetRevision::create([
            'budget_id'       => $budget->id,
            'revision_number' => 1,
            'previous_amount' => '100000.00',
            'revised_amount'  => '110000.00',
        ]);

        $this->assertCount(1, $budget->revisions);
    }

    /** @test */
    public function budget_has_many_commitments(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        Commitment::create(['budget_id' => $budget->id, 'amount' => '5000.00']);
        Commitment::create(['budget_id' => $budget->id, 'amount' => '8000.00']);

        $this->assertCount(2, $budget->commitments);
    }

    /** @test */
    public function budget_has_many_actual_costs(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        ActualCost::create(['budget_id' => $budget->id, 'amount' => '3000.00']);

        $this->assertCount(1, $budget->actualCosts);
    }

    /** @test */
    public function cost_code_has_many_budget_lines(): void
    {
        $costCode = CostCode::create([
            'tenant_slug' => 'test-tenant',
            'code'        => 'CC-LAB',
            'name'        => 'Labour',
        ]);

        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'Test Budget',
            'total_amount' => '100000.00',
        ]);

        BudgetLine::create([
            'budget_id'    => $budget->id,
            'cost_code_id' => $costCode->id,
            'planned_amount' => '10000.00',
        ]);

        $this->assertCount(1, $costCode->budgetLines);
    }

    // -------------------------------------------------------------------------
    // UUID Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function budget_uses_uuid_primary_key(): void
    {
        $budget = Budget::create([
            'tenant_slug'  => 'test-tenant',
            'name'         => 'UUID Test Budget',
            'total_amount' => '1000.00',
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $budget->id,
            'Budget primary key must be a valid UUID'
        );
    }

    /** @test */
    public function cost_code_uses_uuid_primary_key(): void
    {
        $costCode = CostCode::create([
            'tenant_slug' => 'test-tenant',
            'code'        => 'CC-UUID',
            'name'        => 'UUID Test',
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $costCode->id,
            'CostCode primary key must be a valid UUID'
        );
    }

    // -------------------------------------------------------------------------
    // ServiceProvider Test
    // -------------------------------------------------------------------------

    /** @test */
    public function cost_control_service_provider_is_registered(): void
    {
        $this->assertInstanceOf(
            \App\Providers\CostControlServiceProvider::class,
            app()->getProvider(\App\Providers\CostControlServiceProvider::class)
        );
    }
}
