<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

use App\Commercial\CommercialService;
use App\Commercial\Contract;
use App\Commercial\ContractVariation;
use App\CRM\Client;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ContractVariationTest — Feature tests for CAP-033 Contract Variations.
 *
 * Covers:
 *  Foundation:
 *   - contract_variations table exists in tenant schema
 *   - migration file exists in correct path
 *   - ContractVariation model persistence (all fields)
 *   - Status helpers (isDraft, isApproved, isRejected)
 *   - contract() relationship
 *  Service (getVariation, listVariations, rejectVariation):
 *   - getVariation returns correct variation
 *   - getVariation throws on not found
 *   - listVariations returns variations for contract
 *   - listVariations throws if contract not found
 *   - rejectVariation transitions to REJECTED
 *   - approveVariation transitions to APPROVED (regression)
 *  HTTP API (new endpoints — CAP-033):
 *   - GET /api/v1/commercial/variations/{id} — 200, 404
 *   - GET /api/v1/commercial/contracts/{id}/variations — 200 list, 404 contract not found
 *   - PATCH /api/v1/commercial/variations/{id}/reject — 200, 404
 *   - POST /api/v1/commercial/variations — full field validation
 *   - Unauthenticated → 401
 *  Tenant isolation:
 *   - Wrong-tenant variation GET → 404 (model-level)
 *   - listVariations returns only contract's variations
 *  Authorization:
 *   - All endpoints require auth:sanctum
 *
 * CAP-033 — Contract Variations
 * OD-013: SUBSTANTIALLY CLOSED — Multi-tier approval detail = SPEC CLARIFICATION (non-blocking)
 */
class ContractVariationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'cap033-variation-test';
    private string $token;
    private User $user;
    private string $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
        $this->contractId = $this->createTestContract();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('contract_variations')->delete();
            DB::connection('tenant')->table('contracts')->delete();
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
            'name'        => 'CAP-033 Variation Test Tenant',
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
        $user  = User::on('tenant')->create([
            'name'     => 'Variation Test User',
            'email'    => 'variationtest@example.com',
            'password' => Hash::make('password'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        return [$user, $token];
    }

    private function createTestContract(): string
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Test Client',
            'status'      => 'active',
            'tenant_slug' => $this->tenantSlug,
        ]);

        $contract = Contract::create([
            'contract_number'           => 'CNT-CAP033-001',
            'client_id'                 => $client->id,
            'contract_value'            => '100000.00',
            'approved_variations_value' => '0',
            'revised_contract_value'    => '100000.00',
            'status'                    => 'ACTIVE',
            'tenant_slug'               => $this->tenantSlug,
        ]);

        return $contract->id;
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function createVariation(array $overrides = []): ContractVariation
    {
        return ContractVariation::create(array_merge([
            'contract_id'      => $this->contractId,
            'reason'           => 'Additional scope required',
            'variation_number' => 'VO-001',
            'value_change'     => '5000.00',
            'status'           => 'DRAFT',
            'tenant_slug'      => $this->tenantSlug,
        ], $overrides));
    }

    // =========================================================================
    // FOUNDATION — Schema / Migration
    // =========================================================================

    public function test_contract_variations_table_exists(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('contract_variations'),
            'contract_variations table must exist in tenant schema'
        );
    }

    public function test_contract_variations_migration_exists_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/commercial/2026_09_27_100010_create_contract_variations_table.php')
        );
    }

    // =========================================================================
    // FOUNDATION — Model Persistence
    // =========================================================================

    public function test_variation_can_be_created_with_all_fields(): void
    {
        $variation = ContractVariation::create([
            'contract_id'       => $this->contractId,
            'reason'            => 'Foundation scope change',
            'variation_number'  => 'VO-001',
            'scope_description' => 'Detailed scope description',
            'value_change'      => '15000.00',
            'status'            => 'DRAFT',
            'notes'             => 'Test note',
            'tenant_slug'       => $this->tenantSlug,
        ]);

        $this->assertNotNull($variation->id);
        $this->assertEquals($this->contractId, $variation->contract_id);
        $this->assertEquals('Foundation scope change', $variation->reason);
        $this->assertEquals('VO-001', $variation->variation_number);
        $this->assertEquals('DRAFT', $variation->status);
        $this->assertEquals($this->tenantSlug, $variation->tenant_slug);
    }

    public function test_variation_status_helpers(): void
    {
        $draft     = $this->createVariation(['status' => 'DRAFT']);
        $approved  = $this->createVariation(['status' => 'APPROVED']);
        $rejected  = $this->createVariation(['status' => 'REJECTED']);

        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->isApproved());
        $this->assertFalse($draft->isRejected());

        $this->assertTrue($approved->isApproved());
        $this->assertFalse($approved->isDraft());

        $this->assertTrue($rejected->isRejected());
        $this->assertFalse($rejected->isApproved());
    }

    public function test_variation_contract_relationship(): void
    {
        $variation = $this->createVariation();
        $contract  = $variation->contract;

        $this->assertNotNull($contract);
        $this->assertEquals($this->contractId, $contract->id);
    }

    // =========================================================================
    // SERVICE — getVariation
    // =========================================================================

    public function test_get_variation_returns_correct_variation(): void
    {
        $service   = app(CommercialService::class);
        $variation = $this->createVariation();

        $found = $service->getVariation($variation->id);

        $this->assertEquals($variation->id, $found->id);
        $this->assertEquals('Additional scope required', $found->reason);
    }

    public function test_get_variation_throws_on_not_found(): void
    {
        $service = app(CommercialService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->getVariation('00000000-0000-0000-0000-000000000000');
    }

    public function test_get_variation_throws_on_empty_id(): void
    {
        $service = app(CommercialService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->getVariation('');
    }

    // =========================================================================
    // SERVICE — listVariations
    // =========================================================================

    public function test_list_variations_returns_all_for_contract(): void
    {
        $service = app(CommercialService::class);
        $this->createVariation(['reason' => 'First variation']);
        $this->createVariation(['reason' => 'Second variation']);

        $variations = $service->listVariations($this->contractId);

        $this->assertCount(2, $variations);
    }

    public function test_list_variations_returns_empty_when_none(): void
    {
        $service    = app(CommercialService::class);
        $variations = $service->listVariations($this->contractId);

        $this->assertCount(0, $variations);
    }

    public function test_list_variations_throws_if_contract_not_found(): void
    {
        $service = app(CommercialService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->listVariations('00000000-0000-0000-0000-000000000000');
    }

    // =========================================================================
    // SERVICE — rejectVariation
    // =========================================================================

    public function test_reject_variation_transitions_to_rejected(): void
    {
        $service   = app(CommercialService::class);
        $variation = $this->createVariation(['status' => 'DRAFT']);

        $rejected = $service->rejectVariation($variation->id, $this->user->id);

        $this->assertEquals('REJECTED', $rejected->status);
        $this->assertTrue($rejected->isRejected());
    }

    public function test_reject_variation_throws_on_not_found(): void
    {
        $service = app(CommercialService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->rejectVariation('00000000-0000-0000-0000-000000000000');
    }

    public function test_reject_variation_throws_on_empty_id(): void
    {
        $service = app(CommercialService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->rejectVariation('');
    }

    // =========================================================================
    // SERVICE — approveVariation (regression guard)
    // =========================================================================

    public function test_approve_variation_transitions_to_approved(): void
    {
        $service   = app(CommercialService::class);
        $variation = $this->createVariation(['status' => 'DRAFT']);

        $approved = $service->approveVariation($variation->id, $this->user->id);

        $this->assertEquals('APPROVED', $approved->status);
        $this->assertTrue($approved->isApproved());
        $this->assertNotNull($approved->approved_at);
    }

    // =========================================================================
    // HTTP API — GET /api/v1/commercial/variations/{id}
    // =========================================================================

    public function test_get_variation_http_returns_200(): void
    {
        $variation = $this->createVariation();

        $response = $this->getJson(
            '/api/v1/commercial/variations/' . $variation->id,
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $variation->id])
            ->assertJsonFragment(['reason' => 'Additional scope required'])
            ->assertJsonFragment(['status' => 'DRAFT']);
    }

    public function test_get_variation_http_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/variations/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_get_variation_requires_auth(): void
    {
        $variation = $this->createVariation();

        $response = $this->getJson(
            '/api/v1/commercial/variations/' . $variation->id,
            ['X-Tenant' => $this->tenantSlug]
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // HTTP API — GET /api/v1/commercial/contracts/{id}/variations
    // =========================================================================

    public function test_list_variations_http_returns_200_with_variations(): void
    {
        $this->createVariation(['reason' => 'First']);
        $this->createVariation(['reason' => 'Second']);

        $response = $this->getJson(
            '/api/v1/commercial/contracts/' . $this->contractId . '/variations',
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_list_variations_http_returns_empty_array_when_none(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/contracts/' . $this->contractId . '/variations',
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    public function test_list_variations_http_returns_404_for_unknown_contract(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/contracts/00000000-0000-0000-0000-000000000000/variations',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_list_variations_requires_auth(): void
    {
        $response = $this->getJson(
            '/api/v1/commercial/contracts/' . $this->contractId . '/variations',
            ['X-Tenant' => $this->tenantSlug]
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // HTTP API — PATCH /api/v1/commercial/variations/{id}/reject
    // =========================================================================

    public function test_reject_variation_http_returns_200(): void
    {
        $variation = $this->createVariation(['status' => 'DRAFT']);

        $response = $this->patchJson(
            '/api/v1/commercial/variations/' . $variation->id . '/reject',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'REJECTED']);
    }

    public function test_reject_variation_http_returns_404_for_unknown_id(): void
    {
        $response = $this->patchJson(
            '/api/v1/commercial/variations/00000000-0000-0000-0000-000000000000/reject',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_reject_variation_requires_auth(): void
    {
        $variation = $this->createVariation();

        $response = $this->patchJson(
            '/api/v1/commercial/variations/' . $variation->id . '/reject',
            [],
            ['X-Tenant' => $this->tenantSlug]
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // HTTP API — POST /api/v1/commercial/variations (validation)
    // =========================================================================

    public function test_create_variation_returns_201_with_full_fields(): void
    {
        $response = $this->postJson('/api/v1/commercial/variations', [
            'contract_id'       => $this->contractId,
            'reason'            => 'Additional scope per client request',
            'variation_number'  => 'VO-001',
            'scope_description' => 'Install additional lighting fixtures in block B',
            'value_change'      => 12500.00,
            'notes'             => 'Agreed in site meeting on 2026-10-06',
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['reason' => 'Additional scope per client request'])
            ->assertJsonFragment(['variation_number' => 'VO-001'])
            ->assertJsonFragment(['status' => 'DRAFT']);
    }

    public function test_create_variation_returns_422_when_reason_missing(): void
    {
        $response = $this->postJson('/api/v1/commercial/variations', [
            'contract_id' => $this->contractId,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_create_variation_returns_422_when_contract_id_missing(): void
    {
        $response = $this->postJson('/api/v1/commercial/variations', [
            'reason' => 'Missing contract',
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['contract_id']);
    }

    public function test_create_variation_returns_422_when_contract_not_found(): void
    {
        $response = $this->postJson('/api/v1/commercial/variations', [
            'contract_id' => '00000000-0000-0000-0000-000000000000',
            'reason'      => 'Contract does not exist',
        ], $this->authHeaders());

        // Service throws InvalidArgumentException → 422
        $response->assertStatus(422);
    }

    // =========================================================================
    // TENANT ISOLATION
    // =========================================================================

    public function test_list_variations_only_returns_variations_for_the_specified_contract(): void
    {
        // Create another contract for the same tenant
        $client2 = Client::create([
            'type'        => 'company',
            'name'        => 'Second Client',
            'status'      => 'active',
            'tenant_slug' => $this->tenantSlug,
        ]);
        $contract2 = Contract::create([
            'contract_number'           => 'CNT-CAP033-002',
            'client_id'                 => $client2->id,
            'contract_value'            => '200000.00',
            'approved_variations_value' => '0',
            'revised_contract_value'    => '200000.00',
            'status'                    => 'ACTIVE',
            'tenant_slug'               => $this->tenantSlug,
        ]);

        // Variation for contract 1
        $this->createVariation(['reason' => 'Contract 1 variation']);

        // Variation for contract 2
        ContractVariation::create([
            'contract_id'  => $contract2->id,
            'reason'       => 'Contract 2 variation',
            'value_change' => '1000.00',
            'status'       => 'DRAFT',
            'tenant_slug'  => $this->tenantSlug,
        ]);

        // List variations for contract 1 only
        $response = $this->getJson(
            '/api/v1/commercial/contracts/' . $this->contractId . '/variations',
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertEquals('Contract 1 variation', $response->json()[0]['reason']);
    }

    public function test_variation_tenant_slug_is_set_from_context_not_from_request(): void
    {
        $response = $this->postJson('/api/v1/commercial/variations', [
            'contract_id' => $this->contractId,
            'reason'      => 'Tenant slug test',
            'tenant_slug' => 'attacker-tenant', // should be ignored
        ], $this->authHeaders());

        $response->assertStatus(201);
        $variationId = $response->json('id');

        $variation = ContractVariation::find($variationId);
        $this->assertEquals($this->tenantSlug, $variation->tenant_slug);
        $this->assertNotEquals('attacker-tenant', $variation->tenant_slug);
    }

    public function test_variation_belongs_to_correct_tenant_slug(): void
    {
        $variation = ContractVariation::create([
            'contract_id'  => $this->contractId,
            'reason'       => 'Tenant ownership test',
            'value_change' => '0',
            'status'       => 'DRAFT',
            'tenant_slug'  => $this->tenantSlug,
        ]);

        $found = ContractVariation::where('id', $variation->id)
            ->where('tenant_slug', $this->tenantSlug)
            ->first();

        $this->assertNotNull($found, 'Variation must be findable by tenant_slug scope');
        $this->assertEquals($this->tenantSlug, $found->tenant_slug);

        // Wrong tenant scope should return null
        $wrongTenantResult = ContractVariation::where('id', $variation->id)
            ->where('tenant_slug', 'wrong-tenant')
            ->first();

        $this->assertNull($wrongTenantResult, 'Variation must NOT be findable by wrong tenant_slug');
    }
}
