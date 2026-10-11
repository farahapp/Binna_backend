<?php

declare(strict_types=1);

namespace Tests\Feature\CostControl;

use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CostControlHttpApiTest — Feature tests for TASK-006C Cost Control HTTP API Layer.
 *
 * Tests:
 *  - Authentication boundary — all endpoints return 401 without token
 *  - POST /api/v1/cost-control/budgets                                    (201)
 *  - PATCH /api/v1/cost-control/budgets/{budgetId}/approve                (200)
 *  - GET  /api/v1/cost-control/budgets/{projectId}                        (200 + 404)
 *  - POST /api/v1/cost-control/commitments                                (201)
 *  - GET  /api/v1/cost-control/projects/{projectId}/commitment-balance    (200)
 *  - POST /api/v1/cost-control/actual-costs                               (201)
 *  - GET  /api/v1/cost-control/projects/{projectId}/cost-position         (200)
 *  - Tenant isolation (X-Tenant header required)
 *
 * TASK-006C — Cost Control HTTP API Layer
 */
class CostControlHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'cost-control-http-test';
    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('actual_costs')->delete();
            DB::connection('tenant')->table('commitments')->delete();
            DB::connection('tenant')->table('budget_revisions')->delete();
            DB::connection('tenant')->table('budget_lines')->delete();
            DB::connection('tenant')->table('budgets')->delete();
            DB::connection('tenant')->table('approval_requests')->delete();
            DB::connection('tenant')->table('audit_entries')->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    // =========================================================================
    // SETUP HELPERS
    // =========================================================================

    private function setUpTenant(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Cost Control HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', '127.0.0.1');
        Config::set('database.connections.tenant.port', '3306');
        Config::set('database.connections.tenant.database', 'binaa2_testing');
        Config::set('database.connections.tenant.username', config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password', config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset', 'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix', '');
        Config::set('database.connections.tenant.strict', true);
        DB::purge('tenant');
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Cost Control Test User',
            'email'    => 'costcontroltest@example.com',
            'password' => Hash::make('password'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        return [$user, $token];
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenantSlug];
    }

    /**
     * Helper: create a budget via the API and return its ID.
     */
    private function createBudgetViaApi(array $overrides = []): string
    {
        $payload = array_merge([
            'name'         => 'Test Budget',
            'total_amount' => 100000.00,
        ], $overrides);

        $response = $this->postJson('/api/v1/cost-control/budgets', $payload, $this->authHeaders());
        $response->assertStatus(201);
        return $response->json('id');
    }

    /**
     * Helper: create and approve a budget, return its ID.
     */
    private function createAndApproveBudgetViaApi(array $overrides = []): string
    {
        $budgetId = $this->createBudgetViaApi($overrides);

        $this->patchJson(
            "/api/v1/cost-control/budgets/{$budgetId}/approve",
            [],
            $this->authHeaders()
        )->assertStatus(200);

        return $budgetId;
    }

    // =========================================================================
    // UNAUTHENTICATED — 401
    // =========================================================================

    public function test_unauthenticated_post_budget_returns_401(): void
    {
        $response = $this->postJson('/api/v1/cost-control/budgets', [
            'name'         => 'Unauthorized Budget',
            'total_amount' => 50000.00,
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_unauthenticated_get_budget_returns_401(): void
    {
        $response = $this->getJson(
            '/api/v1/cost-control/budgets/00000000-0000-0000-0000-000000000000',
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    public function test_unauthenticated_post_commitment_returns_401(): void
    {
        $response = $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id' => '00000000-0000-0000-0000-000000000000',
            'amount'    => 1000.00,
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_unauthenticated_post_actual_cost_returns_401(): void
    {
        $response = $this->postJson('/api/v1/cost-control/actual-costs', [
            'budget_id' => '00000000-0000-0000-0000-000000000000',
            'amount'    => 500.00,
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_unauthenticated_get_cost_position_returns_401(): void
    {
        $response = $this->getJson(
            '/api/v1/cost-control/projects/00000000-0000-0000-0000-000000000000/cost-position',
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // POST /api/v1/cost-control/budgets — Create Budget
    // =========================================================================

    public function test_create_budget_returns_201(): void
    {
        $response = $this->postJson('/api/v1/cost-control/budgets', [
            'name'         => 'Construction Budget',
            'total_amount' => 500000.00,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Construction Budget']);
        $this->assertNotNull($response->json('id'));
        $this->assertSame('draft', $response->json('status'));
    }

    public function test_create_budget_with_all_fields_returns_201(): void
    {
        $response = $this->postJson('/api/v1/cost-control/budgets', [
            'name'         => 'Full Budget',
            'total_amount' => 750000.00,
            'currency'     => 'SAR',
            'fiscal_year'  => 2026,
            'description'  => 'Annual construction budget',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Full Budget']);
        $response->assertJsonFragment(['currency' => 'SAR']);
    }

    public function test_create_budget_missing_name_returns_422(): void
    {
        $response = $this->postJson('/api/v1/cost-control/budgets', [
            'total_amount' => 100000.00,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_create_budget_missing_total_amount_returns_422(): void
    {
        $response = $this->postJson('/api/v1/cost-control/budgets', [
            'name' => 'Budget Without Amount',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // PATCH /api/v1/cost-control/budgets/{budgetId}/approve — Approve Budget
    // =========================================================================

    public function test_approve_budget_returns_200(): void
    {
        $budgetId = $this->createBudgetViaApi(['name' => 'Budget To Approve']);

        $response = $this->patchJson(
            "/api/v1/cost-control/budgets/{$budgetId}/approve",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'active']);
    }

    public function test_approve_budget_with_notes_returns_200(): void
    {
        $budgetId = $this->createBudgetViaApi(['name' => 'Budget With Notes']);

        $response = $this->patchJson(
            "/api/v1/cost-control/budgets/{$budgetId}/approve",
            ['notes' => 'Approved by finance committee'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'active']);
    }

    public function test_approve_nonexistent_budget_returns_422(): void
    {
        $response = $this->patchJson(
            '/api/v1/cost-control/budgets/00000000-0000-0000-0000-000000000000/approve',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/cost-control/budgets/{projectId} — Get Budget
    // =========================================================================

    public function test_get_budget_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/cost-control/budgets/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Budget not found.']);
    }

    public function test_get_budget_found_returns_200(): void
    {
        $projectId = '00000000-0000-0000-0000-000000000001';
        $this->createBudgetViaApi([
            'name'       => 'Project Budget',
            'project_id' => $projectId,
        ]);

        $response = $this->getJson(
            "/api/v1/cost-control/budgets/{$projectId}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertNotNull($response->json('id'));
    }

    // =========================================================================
    // POST /api/v1/cost-control/commitments — Record Commitment
    // =========================================================================

    public function test_record_commitment_returns_201(): void
    {
        $budgetId = $this->createAndApproveBudgetViaApi(['name' => 'Commitment Budget']);

        $response = $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id' => $budgetId,
            'amount'    => 25000.00,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $this->assertNotNull($response->json('id'));
        $response->assertJsonFragment(['budget_id' => $budgetId]);
    }

    public function test_record_commitment_with_all_fields_returns_201(): void
    {
        $budgetId = $this->createAndApproveBudgetViaApi(['name' => 'Full Commitment Budget']);

        $response = $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id'      => $budgetId,
            'amount'         => 15000.00,
            'description'    => 'Steel procurement commitment',
            'committed_date' => '2025-07-01',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['budget_id' => $budgetId]);
    }

    public function test_record_commitment_missing_budget_id_returns_422(): void
    {
        $response = $this->postJson('/api/v1/cost-control/commitments', [
            'amount' => 5000.00,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_record_commitment_missing_amount_returns_422(): void
    {
        $budgetId = $this->createAndApproveBudgetViaApi(['name' => 'Validation Budget']);

        $response = $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id' => $budgetId,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_record_commitment_against_draft_budget_returns_422(): void
    {
        $budgetId = $this->createBudgetViaApi(['name' => 'Draft Budget']);

        $response = $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id' => $budgetId,
            'amount'    => 5000.00,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/cost-control/projects/{projectId}/commitment-balance
    // =========================================================================

    public function test_get_commitment_balance_returns_200(): void
    {
        $projectId = '00000000-0000-0000-0000-000000000002';
        $budgetId  = $this->createAndApproveBudgetViaApi([
            'name'       => 'Balance Budget',
            'project_id' => $projectId,
        ]);

        // Record a commitment
        $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id' => $budgetId,
            'amount'    => 30000.00,
        ], $this->authHeaders())->assertStatus(201);

        $response = $this->getJson(
            "/api/v1/cost-control/projects/{$projectId}/commitment-balance",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertArrayHasKey('balance', $response->json());
    }

    public function test_get_commitment_balance_no_budget_returns_zero(): void
    {
        $response = $this->getJson(
            '/api/v1/cost-control/projects/00000000-0000-0000-0000-000000000099/commitment-balance',
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['balance' => 0.0]);
    }

    // =========================================================================
    // POST /api/v1/cost-control/actual-costs — Record Actual Cost
    // =========================================================================

    public function test_record_actual_cost_returns_201(): void
    {
        $budgetId = $this->createAndApproveBudgetViaApi(['name' => 'Actual Cost Budget']);

        $response = $this->postJson('/api/v1/cost-control/actual-costs', [
            'budget_id' => $budgetId,
            'amount'    => 12000.00,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $this->assertNotNull($response->json('id'));
        $response->assertJsonFragment(['budget_id' => $budgetId]);
    }

    public function test_record_actual_cost_with_all_fields_returns_201(): void
    {
        $budgetId = $this->createAndApproveBudgetViaApi(['name' => 'Full Actual Cost Budget']);

        $response = $this->postJson('/api/v1/cost-control/actual-costs', [
            'budget_id'   => $budgetId,
            'amount'      => 8500.00,
            'description' => 'Concrete delivery invoice',
            'cost_date'   => '2025-07-15',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['budget_id' => $budgetId]);
    }

    public function test_record_actual_cost_missing_budget_id_returns_422(): void
    {
        $response = $this->postJson('/api/v1/cost-control/actual-costs', [
            'amount' => 3000.00,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_record_actual_cost_missing_amount_returns_422(): void
    {
        $budgetId = $this->createAndApproveBudgetViaApi(['name' => 'Actual Cost Validation Budget']);

        $response = $this->postJson('/api/v1/cost-control/actual-costs', [
            'budget_id' => $budgetId,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/cost-control/projects/{projectId}/cost-position
    // =========================================================================

    public function test_get_cost_position_returns_200(): void
    {
        $projectId = '00000000-0000-0000-0000-000000000003';
        $budgetId  = $this->createAndApproveBudgetViaApi([
            'name'         => 'Cost Position Budget',
            'total_amount' => 200000.00,
            'project_id'   => $projectId,
        ]);

        // Record a commitment
        $this->postJson('/api/v1/cost-control/commitments', [
            'budget_id' => $budgetId,
            'amount'    => 50000.00,
        ], $this->authHeaders())->assertStatus(201);

        // Record an actual cost
        $this->postJson('/api/v1/cost-control/actual-costs', [
            'budget_id' => $budgetId,
            'amount'    => 20000.00,
        ], $this->authHeaders())->assertStatus(201);

        $response = $this->getJson(
            "/api/v1/cost-control/projects/{$projectId}/cost-position",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertArrayHasKey('budget_amount', $response->json());
        $this->assertArrayHasKey('committed_amount', $response->json());
        $this->assertArrayHasKey('actual_amount', $response->json());
        $this->assertArrayHasKey('remaining', $response->json());
    }

    public function test_get_cost_position_no_budget_returns_zeros(): void
    {
        $response = $this->getJson(
            '/api/v1/cost-control/projects/00000000-0000-0000-0000-000000000098/cost-position',
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'budget_amount'    => 0.0,
            'committed_amount' => 0.0,
            'actual_amount'    => 0.0,
            'remaining'        => 0.0,
        ]);
    }

    // =========================================================================
    // TENANT ISOLATION
    // =========================================================================

    public function test_request_without_tenant_header_returns_error(): void
    {
        $response = $this->postJson('/api/v1/cost-control/budgets', [
            'name'         => 'No Tenant Budget',
            'total_amount' => 10000.00,
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        // Without X-Tenant header, the tenant middleware should reject or fail
        $this->assertContains($response->status(), [400, 401, 404, 422, 500]);
    }
}
