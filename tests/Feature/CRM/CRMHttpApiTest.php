<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\CRM\Client;
use App\CRM\Contact;
use App\CRM\Lead;
use App\CRM\Opportunity;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CRMHttpApiTest — Feature tests for TASK-003C CRM HTTP API Layer.
 *
 * Tests:
 *  - POST /api/v1/crm/clients (company + individual + validation)
 *  - GET  /api/v1/crm/clients
 *  - GET  /api/v1/crm/clients/{id} (found + not found)
 *  - GET  /api/v1/crm/contacts/{id} (found + not found)
 *  - POST /api/v1/crm/leads
 *  - POST /api/v1/crm/opportunities
 *  - PATCH /api/v1/crm/opportunities/{id}/won
 *  - PATCH /api/v1/crm/opportunities/{id}/lost
 *  - POST /api/v1/crm/activities
 *  - Unauthenticated → 401
 *
 * TASK-003C — CRM HTTP API Layer
 */
class CRMHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'crm-http-test';
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
        // Insert tenant record
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'CRM HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Configure tenant connection to point to test database
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
            'name'     => 'CRM Test User',
            'email'    => 'crmtest@example.com',
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

    // =========================================================================
    // UNAUTHENTICATED — 401
    // =========================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Test Client',
            'type' => 'company',
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    // =========================================================================
    // POST /api/v1/crm/clients
    // =========================================================================

    public function test_create_client_company_returns_201(): void
    {
        $response = $this->postJson('/api/v1/crm/clients', [
            'name'  => 'Acme Corp',
            'type'  => 'company',
            'email' => 'acme@example.com',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Acme Corp', 'type' => 'company']);
    }

    public function test_create_client_individual_returns_201(): void
    {
        $response = $this->postJson('/api/v1/crm/clients', [
            'name' => 'John Doe',
            'type' => 'individual',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'John Doe', 'type' => 'individual']);
    }

    public function test_create_client_validation_failure_returns_422(): void
    {
        $response = $this->postJson('/api/v1/crm/clients', [
            // missing required 'name' and 'type'
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_create_client_invalid_type_returns_422(): void
    {
        $response = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Test',
            'type' => 'invalid_type',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/crm/clients
    // =========================================================================

    public function test_list_clients_returns_200(): void
    {
        $response = $this->getJson('/api/v1/crm/clients', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    // =========================================================================
    // GET /api/v1/crm/clients/{id}
    // =========================================================================

    public function test_get_client_found_returns_200(): void
    {
        // Create a client first
        $createResponse = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Find Me Corp',
            'type' => 'company',
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $clientId = $createResponse->json('id');

        $response = $this->getJson("/api/v1/crm/clients/{$clientId}", $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $clientId]);
    }

    public function test_get_client_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/crm/clients/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // GET /api/v1/crm/contacts/{id}
    // =========================================================================

    public function test_get_contact_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/crm/contacts/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // POST /api/v1/crm/leads
    // =========================================================================

    public function test_create_lead_returns_201(): void
    {
        // Create a client first
        $clientResponse = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Lead Client',
            'type' => 'company',
        ], $this->authHeaders());
        $clientId = $clientResponse->json('id');

        $response = $this->postJson('/api/v1/crm/leads', [
            'client_id' => $clientId,
            'title'     => 'New Lead',
        ], $this->authHeaders());

        $response->assertStatus(201);
    }

    // =========================================================================
    // POST /api/v1/crm/opportunities
    // =========================================================================

    public function test_create_opportunity_returns_201(): void
    {
        $clientResponse = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Opp Client',
            'type' => 'company',
        ], $this->authHeaders());
        $clientId = $clientResponse->json('id');

        $response = $this->postJson('/api/v1/crm/opportunities', [
            'client_id' => $clientId,
            'title'     => 'Big Deal',
        ], $this->authHeaders());

        $response->assertStatus(201);
    }

    // =========================================================================
    // PATCH /api/v1/crm/opportunities/{id}/won
    // =========================================================================

    public function test_mark_opportunity_won_returns_200(): void
    {
        $clientResponse = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Won Client',
            'type' => 'company',
        ], $this->authHeaders());
        $clientId = $clientResponse->json('id');

        $oppResponse = $this->postJson('/api/v1/crm/opportunities', [
            'client_id' => $clientId,
            'title'     => 'Win This',
        ], $this->authHeaders());
        $oppId = $oppResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/crm/opportunities/{$oppId}/won",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'won']);
    }

    // =========================================================================
    // PATCH /api/v1/crm/opportunities/{id}/lost
    // =========================================================================

    public function test_mark_opportunity_lost_returns_200(): void
    {
        $clientResponse = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Lost Client',
            'type' => 'company',
        ], $this->authHeaders());
        $clientId = $clientResponse->json('id');

        $oppResponse = $this->postJson('/api/v1/crm/opportunities', [
            'client_id' => $clientId,
            'title'     => 'Lose This',
        ], $this->authHeaders());
        $oppId = $oppResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/crm/opportunities/{$oppId}/lost",
            ['lost_reason' => 'Budget cut'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'lost']);
    }

    // =========================================================================
    // POST /api/v1/crm/activities
    // =========================================================================

    public function test_record_activity_returns_201(): void
    {
        $clientResponse = $this->postJson('/api/v1/crm/clients', [
            'name' => 'Activity Client',
            'type' => 'company',
        ], $this->authHeaders());
        $clientId = $clientResponse->json('id');

        $response = $this->postJson('/api/v1/crm/activities', [
            'subject_type' => 'client',
            'subject_id'   => $clientId,
            'type'         => 'call',
            'notes'        => 'Initial call',
        ], $this->authHeaders());

        $response->assertStatus(201);
    }
}
