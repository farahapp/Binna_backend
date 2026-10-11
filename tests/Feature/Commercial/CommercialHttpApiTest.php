<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\CRM\Client;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CommercialHttpApiTest — Feature tests for TASK-004C Commercial HTTP API Layer.
 *
 * Tests:
 *  - Authentication boundary — all endpoints return 401 without token
 *  - GET  /api/v1/commercial/contracts/{id}       (found + not found)
 *  - GET  /api/v1/commercial/contracts/{id}/value (200 with structure)
 *  - GET  /api/v1/commercial/boqs/{id}            (found + not found)
 *  - POST /api/v1/commercial/tenders              (201 + 422 validation)
 *  - POST /api/v1/commercial/contracts            (201)
 *  - PATCH /api/v1/commercial/tenders/{id}/award  (200 + 404)
 *  - PATCH /api/v1/commercial/contracts/{id}/sign (200)
 *  - PATCH /api/v1/commercial/quotations/{id}/accept (200)
 *  - PATCH /api/v1/commercial/variations/{id}/approve (200)
 *
 * TASK-004C — Commercial HTTP API Layer
 */
class CommercialHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'commercial-http-test';
    private string $token;
    private User $user;
    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
        $this->clientId = $this->createTestClient();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('contract_variations')->delete();
            DB::connection('tenant')->table('contracts')->delete();
            DB::connection('tenant')->table('quotations')->delete();
            DB::connection('tenant')->table('boqs')->delete();
            DB::connection('tenant')->table('tenders')->delete();
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
            'name'        => 'Commercial HTTP Test Tenant',
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
            'name'     => 'Commercial Test User',
            'email'    => 'commercialtest@example.com',
            'password' => Hash::make('password'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        return [$user, $token];
    }

    private function createTestClient(): string
    {
        $client = Client::on('tenant')->create([
            'type'        => 'company',
            'name'        => 'Commercial HTTP Test Client',
            'status'      => 'active',
            'tenant_slug' => $this->tenantSlug,
        ]);
        return $client->id;
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
        $response = $this->postJson('/api/v1/commercial/tenders', [
            'title'     => 'Test Tender',
            'client_id' => 'some-client-id',
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_unauthenticated_get_contract_returns_401(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/contracts/00000000-0000-0000-0000-000000000000',
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    public function test_unauthenticated_post_contract_returns_401(): void
    {
        $response = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-001',
            'client_id'       => 'some-client-id',
            'contract_value'  => 100000,
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    // =========================================================================
    // GET /api/v1/commercial/contracts/{id}
    // =========================================================================

    public function test_get_contract_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/contracts/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Contract not found.']);
    }

    public function test_get_contract_found_returns_200(): void
    {
        // Create a contract first
        $createResponse = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-TEST-001',
            'client_id'       => $this->clientId,
            'contract_value'  => 500000,
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $contractId = $createResponse->json('id');

        $response = $this->getJson(
            "/api/v1/commercial/contracts/{$contractId}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $contractId]);
    }

    // =========================================================================
    // GET /api/v1/commercial/contracts/{id}/value
    // =========================================================================

    public function test_get_contract_value_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/contracts/00000000-0000-0000-0000-000000000000/value',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_get_contract_value_returns_200_with_structure(): void
    {
        $createResponse = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-VAL-001',
            'client_id'       => $this->clientId,
            'contract_value'  => 750000,
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $contractId = $createResponse->json('id');

        $response = $this->getJson(
            "/api/v1/commercial/contracts/{$contractId}/value",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    // =========================================================================
    // GET /api/v1/commercial/boqs/{id}
    // =========================================================================

    public function test_get_boq_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/boqs/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'BOQ not found.']);
    }

    public function test_get_boq_found_returns_200(): void
    {
        $createResponse = $this->postJson('/api/v1/commercial/boqs', [
            'title' => 'Test BOQ',
            'type'  => 'tender_boq',
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $boqId = $createResponse->json('id');

        $response = $this->getJson(
            "/api/v1/commercial/boqs/{$boqId}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $boqId]);
    }

    // =========================================================================
    // POST /api/v1/commercial/tenders
    // =========================================================================

    public function test_create_tender_returns_201(): void
    {
        $response = $this->postJson('/api/v1/commercial/tenders', [
            'title'     => 'New Infrastructure Tender',
            'client_id' => $this->clientId,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['title' => 'New Infrastructure Tender']);
    }

    public function test_create_tender_with_all_fields_returns_201(): void
    {
        $response = $this->postJson('/api/v1/commercial/tenders', [
            'title'       => 'Full Tender',
            'client_id'   => $this->clientId,
            'description' => 'A detailed tender description',
            'due_date'    => '2025-12-31',
        ], $this->authHeaders());

        $response->assertStatus(201);
    }

    public function test_create_tender_missing_title_returns_422(): void
    {
        $response = $this->postJson('/api/v1/commercial/tenders', [
            'client_id' => $this->clientId,
            // missing required 'title'
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_create_tender_missing_client_id_returns_422(): void
    {
        $response = $this->postJson('/api/v1/commercial/tenders', [
            'title' => 'Tender Without Client',
            // missing required 'client_id'
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /api/v1/commercial/contracts
    // =========================================================================

    public function test_create_contract_returns_201(): void
    {
        $response = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-2025-001',
            'client_id'       => $this->clientId,
            'contract_value'  => 1000000,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['contract_number' => 'C-2025-001']);
    }

    public function test_create_contract_with_dates_returns_201(): void
    {
        $response = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-2025-002',
            'client_id'       => $this->clientId,
            'contract_value'  => 250000,
            'start_date'      => '2025-01-01',
            'end_date'        => '2025-12-31',
        ], $this->authHeaders());

        $response->assertStatus(201);
    }

    public function test_create_contract_missing_required_fields_returns_422(): void
    {
        $response = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-INCOMPLETE',
            // missing client_id and contract_value
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // PATCH /api/v1/commercial/tenders/{id}/award
    // =========================================================================

    public function test_award_tender_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/commercial/tenders/00000000-0000-0000-0000-000000000000/award',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_award_tender_returns_200(): void
    {
        $createResponse = $this->postJson('/api/v1/commercial/tenders', [
            'title'     => 'Tender To Award',
            'client_id' => $this->clientId,
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $tenderId = $createResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/commercial/tenders/{$tenderId}/award",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
    }

    // =========================================================================
    // PATCH /api/v1/commercial/contracts/{id}/sign
    // =========================================================================

    public function test_sign_contract_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/commercial/contracts/00000000-0000-0000-0000-000000000000/sign',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_sign_contract_returns_200(): void
    {
        $createResponse = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-SIGN-001',
            'client_id'       => $this->clientId,
            'contract_value'  => 300000,
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $contractId = $createResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/commercial/contracts/{$contractId}/sign",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
    }

    // =========================================================================
    // PATCH /api/v1/commercial/quotations/{id}/accept
    // =========================================================================

    public function test_accept_quotation_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/commercial/quotations/00000000-0000-0000-0000-000000000000/accept',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_accept_quotation_returns_200(): void
    {
        $createResponse = $this->postJson('/api/v1/commercial/quotations', [
            'quotation_number' => 'Q-2025-001',
            'client_id'        => $this->clientId,
        ], $this->authHeaders());

        $createResponse->assertStatus(201);
        $quotationId = $createResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/commercial/quotations/{$quotationId}/accept",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
    }

    // =========================================================================
    // PATCH /api/v1/commercial/variations/{id}/approve
    // =========================================================================

    public function test_approve_variation_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/commercial/variations/00000000-0000-0000-0000-000000000000/approve',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_approve_variation_returns_200(): void
    {
        // Create a contract first
        $contractResponse = $this->postJson('/api/v1/commercial/contracts', [
            'contract_number' => 'C-VAR-001',
            'client_id'       => $this->clientId,
            'contract_value'  => 400000,
        ], $this->authHeaders());

        $contractResponse->assertStatus(201);
        $contractId = $contractResponse->json('id');

        // Create a variation
        $variationResponse = $this->postJson('/api/v1/commercial/variations', [
            'contract_id' => $contractId,
            'reason'      => 'Scope change',
            'amount'      => 50000,
        ], $this->authHeaders());

        $variationResponse->assertStatus(201);
        $variationId = $variationResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/commercial/variations/{$variationId}/approve",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
    }
}
