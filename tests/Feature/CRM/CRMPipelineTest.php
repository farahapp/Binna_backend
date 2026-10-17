<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\CRM\Lead;
use App\CRM\Opportunity;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CRMPipelineTest — Feature tests for CAP-020 CRM Pipeline HTTP API.
 *
 * Tests:
 *  - GET  /api/v1/crm/leads (list, stage filter, invalid stage, auth)
 *  - GET  /api/v1/crm/opportunities (list, stage filter, invalid stage, auth)
 *  - PATCH /api/v1/crm/leads/{id}/stage
 *  - PATCH /api/v1/crm/opportunities/{id}/stage
 *  - Tenant isolation for leads
 *
 * CAP-020 — CRM Pipeline Implementation
 */
class CRMPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'crm-pipeline-test';
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
            DB::connection('tenant')->table('crm_activities')->delete();
            DB::connection('tenant')->table('opportunities')->delete();
            DB::connection('tenant')->table('leads')->delete();
            DB::connection('tenant')->table('contacts')->delete();
            DB::connection('tenant')->table('clients')->delete();
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
            'name'        => 'CRM Pipeline Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Pipeline Test User',
            'email'    => 'pipeline@example.com',
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

    /** Create a client and return its ID. */
    private function createClient(string $name = 'Pipeline Client'): string
    {
        $response = $this->postJson('/api/v1/crm/clients', [
            'name' => $name,
            'type' => 'company',
        ], $this->authHeaders());

        $response->assertStatus(201);
        return $response->json('id');
    }

    /** Create a lead via the API and return its ID. */
    private function createLead(string $clientId, string $title = 'Test Lead'): string
    {
        $response = $this->postJson('/api/v1/crm/leads', [
            'client_id' => $clientId,
            'title'     => $title,
        ], $this->authHeaders());

        $response->assertStatus(201);
        return $response->json('id');
    }

    /** Create an opportunity via the API and return its ID. */
    private function createOpportunity(string $clientId, string $title = 'Test Opportunity'): string
    {
        $response = $this->postJson('/api/v1/crm/opportunities', [
            'client_id' => $clientId,
            'title'     => $title,
        ], $this->authHeaders());

        $response->assertStatus(201);
        return $response->json('id');
    }

    // =========================================================================
    // GET /api/v1/crm/leads
    // =========================================================================

    public function test_list_leads_returns_200_with_empty_list(): void
    {
        $response = $this->getJson('/api/v1/crm/leads', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_list_leads_with_valid_stage_filter_returns_200(): void
    {
        $response = $this->getJson('/api/v1/crm/leads?stage=new', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_list_leads_with_invalid_stage_returns_422(): void
    {
        $response = $this->getJson('/api/v1/crm/leads?stage=invalid', $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/crm/opportunities
    // =========================================================================

    public function test_list_opportunities_returns_200_with_empty_list(): void
    {
        $response = $this->getJson('/api/v1/crm/opportunities', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_list_opportunities_with_valid_stage_filter_returns_200(): void
    {
        $response = $this->getJson('/api/v1/crm/opportunities?stage=open', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    public function test_list_opportunities_with_invalid_stage_returns_422(): void
    {
        $response = $this->getJson('/api/v1/crm/opportunities?stage=invalid', $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // PATCH /api/v1/crm/leads/{id}/stage
    // =========================================================================

    public function test_update_lead_stage_returns_200(): void
    {
        $clientId = $this->createClient('Stage Lead Client');
        $leadId   = $this->createLead($clientId, 'Stage Update Lead');

        $response = $this->patchJson(
            "/api/v1/crm/leads/{$leadId}/stage",
            ['stage' => 'contacted'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'contacted']);
    }

    public function test_update_lead_stage_with_invalid_stage_returns_422(): void
    {
        $clientId = $this->createClient('Invalid Stage Lead Client');
        $leadId   = $this->createLead($clientId, 'Invalid Stage Lead');

        $response = $this->patchJson(
            "/api/v1/crm/leads/{$leadId}/stage",
            ['stage' => 'invalid'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_update_lead_stage_with_nonexistent_id_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/crm/leads/00000000-0000-0000-0000-000000000000/stage',
            ['stage' => 'contacted'],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // PATCH /api/v1/crm/opportunities/{id}/stage
    // =========================================================================

    public function test_update_opportunity_stage_returns_200(): void
    {
        $clientId = $this->createClient('Stage Opp Client');
        $oppId    = $this->createOpportunity($clientId, 'Stage Update Opportunity');

        $response = $this->patchJson(
            "/api/v1/crm/opportunities/{$oppId}/stage",
            ['stage' => 'cancelled'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_update_opportunity_stage_with_invalid_stage_returns_422(): void
    {
        $clientId = $this->createClient('Invalid Stage Opp Client');
        $oppId    = $this->createOpportunity($clientId, 'Invalid Stage Opportunity');

        $response = $this->patchJson(
            "/api/v1/crm/opportunities/{$oppId}/stage",
            ['stage' => 'invalid'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_update_opportunity_stage_with_nonexistent_id_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/crm/opportunities/00000000-0000-0000-0000-000000000000/stage',
            ['stage' => 'cancelled'],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // AUTHENTICATION — 401
    // =========================================================================

    public function test_list_leads_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/crm/leads', $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_list_opportunities_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/crm/opportunities', $this->tenantHeaders());

        $response->assertStatus(401);
    }

    // =========================================================================
    // TENANT ISOLATION
    // =========================================================================

    public function test_tenant_isolation_leads_scoped_to_active_tenant(): void
    {
        // Create a lead under the active tenant (crm-pipeline-test → binaa2_testing)
        $clientId = $this->createClient('Isolation Client');
        $this->createLead($clientId, 'Tenant A Lead');

        // Register a second tenant slug that also maps to binaa2_testing.
        // This simulates a different tenant context without requiring a separate
        // physical schema — the token issued for tenant A is not valid for tenant B,
        // so the middleware will reject the request with 401 (Sanctum PAT is
        // scoped to the tenant schema where it was created).
        $otherSlug = 'crm-pipeline-other';
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $otherSlug,
            'name'        => 'Other Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Request leads using tenant B slug but tenant A token.
        // SwitchToTenantSchema will keep the same connection (same schema_name),
        // but Sanctum will reject the token because the PAT lookup resolves to
        // the same physical schema — however the tenant context slug differs,
        // so VerifyTenantMembership (if active) or auth:sanctum will return 401.
        $response = $this->getJson('/api/v1/crm/leads', [
            'X-Tenant'      => $otherSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        // Acceptable outcomes that all prove isolation:
        //   200 → leads list must NOT contain Tenant A's lead title
        //   401/403/404 → request was rejected before reaching data layer
        $leads = $response->json();
        if ($response->status() === 200 && is_array($leads)) {
            $titles = array_column($leads, 'title');
            $this->assertNotContains('Tenant A Lead', $titles);
        } else {
            $this->assertContains($response->status(), [200, 401, 403, 404]);
        }

        // Cleanup second tenant record only
        DB::connection('mysql')->table('tenants')->where('slug', $otherSlug)->delete();
    }
}
