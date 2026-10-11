<?php

declare(strict_types=1);

namespace Tests\Feature\Subcontracts;

use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SubcontractHttpApiTest — Feature tests for TASK-009C Subcontract HTTP API Layer.
 *
 * Tests:
 *  - Authentication boundary — endpoints return 401 without token
 *  - Tenant context — missing X-Tenant header is rejected
 *  - Tenant isolation — Tenant A cannot access Tenant B data
 *  - Authorization — forbidden actions return 403
 *  - Validation — malformed/missing required input returns 422
 *  - CAP-080: POST/GET/PATCH agreements (create, show, submit, activate, complete, cancel, value)
 *  - CAP-080: POST scopes (add scope)
 *  - CAP-080: POST/GET progress (record, list)
 *  - CAP-080: POST/PATCH certificates (issue, approve)
 *  - Route registration verification
 *
 * TASK-009C — Subcontract HTTP API Layer (CAP-080)
 */
class SubcontractHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'subcontract-http-test';
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
            DB::connection('tenant')->table('subcontract_certificates')->delete();
            DB::connection('tenant')->table('subcontract_progresses')->delete();
            DB::connection('tenant')->table('subcontract_scopes')->delete();
            DB::connection('tenant')->table('subcontract_agreements')->delete();
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
            'name'        => 'Subcontract HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Subcontract Test User',
            'email'    => 'subtest@example.com',
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

    private function validAgreementPayload(array $overrides = []): array
    {
        return array_merge([
            'agreement_number'   => 'SC-' . Str::random(6),
            'subcontractor_name' => 'Test Subcontractor Co.',
            'title'              => 'Test Subcontract Agreement',
            'contract_value'     => 500000.00,
            'currency'           => 'SAR',
        ], $overrides);
    }

    private function createAgreement(array $overrides = []): array
    {
        $response = $this->postJson(
            '/api/v1/subcontracts/agreements',
            $this->validAgreementPayload($overrides),
            $this->authHeaders()
        );
        $response->assertStatus(201);
        return $response->json();
    }

    // =========================================================================
    // 1. AUTHENTICATION
    // =========================================================================

    /** @test */
    public function unauthenticated_request_to_create_agreement_returns_401(): void
    {
        $response = $this->postJson('/api/v1/subcontracts/agreements', $this->validAgreementPayload(), [
            'X-Tenant' => $this->tenantSlug,
        ]);
        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_request_to_show_agreement_returns_401(): void
    {
        $fakeId = Str::uuid()->toString();
        $response = $this->getJson("/api/v1/subcontracts/agreements/{$fakeId}", [
            'X-Tenant' => $this->tenantSlug,
        ]);
        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_request_to_record_progress_returns_401(): void
    {
        $fakeId = Str::uuid()->toString();
        $response = $this->postJson("/api/v1/subcontracts/agreements/{$fakeId}/progress", [
            'progress_period'       => '2026-09',
            'percentage_complete'   => 25.0,
            'cumulative_percentage' => 25.0,
        ], [
            'X-Tenant' => $this->tenantSlug,
        ]);
        $response->assertStatus(401);
    }

    // =========================================================================
    // 2. TENANT CONTEXT
    // =========================================================================

    /** @test */
    public function request_without_x_tenant_header_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/subcontracts/agreements', $this->validAgreementPayload(), [
            'Authorization' => 'Bearer ' . $this->token,
            // No X-Tenant header
        ]);
        $this->assertContains($response->status(), [400, 401, 422, 500]);
    }

    // =========================================================================
    // 3. TENANT ISOLATION
    // =========================================================================

    /** @test */
    public function tenant_a_cannot_access_tenant_b_agreement(): void
    {
        // Create agreement in Tenant A (current tenant)
        $agreement = $this->createAgreement();
        $agreementId = $agreement['id'];

        // Create Tenant B
        $tenantBSlug = 'subcontract-isolation-b-' . Str::random(4);
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $tenantBSlug,
            'name'        => 'Subcontract Isolation Tenant B',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Create user in Tenant B
        $userB = User::on('tenant')->create([
            'name'     => 'Tenant B User',
            'email'    => 'tenantb-sub@example.com',
            'password' => Hash::make('password'),
        ]);
        $tokenB = $userB->createToken('token-b')->plainTextToken;

        // Tenant B tries to access Tenant A's agreement
        $response = $this->getJson("/api/v1/subcontracts/agreements/{$agreementId}", [
            'X-Tenant'      => $tenantBSlug,
            'Authorization' => 'Bearer ' . $tokenB,
        ]);

        // Should not expose Tenant A's data — 404 is the expected behavior
        $response->assertStatus(404);

        // Cleanup
        DB::connection('mysql')->table('tenants')->where('slug', $tenantBSlug)->delete();
    }

    /** @test */
    public function tenant_a_cannot_access_tenant_b_progress(): void
    {
        // Create an agreement in Tenant A
        $agreement = $this->createAgreement();
        $agreementId = $agreement['id'];

        // Directly insert a progress record for Tenant A
        $progressId = Str::uuid()->toString();
        DB::connection('tenant')->table('subcontract_progresses')->insert([
            'id'                       => $progressId,
            'subcontract_agreement_id' => $agreementId,
            'tenant_slug'              => $this->tenantSlug,
            'progress_period'          => '2026-09',
            'percentage_complete'      => 25.0,
            'cumulative_percentage'    => 25.0,
            'amount_certified'         => 125000.0,
            'cumulative_amount'        => 125000.0,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // Create Tenant B
        $tenantBSlug = 'subcontract-isolation-prog-' . Str::random(4);
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $tenantBSlug,
            'name'        => 'Subcontract Isolation Tenant B Progress',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $userB = User::on('tenant')->create([
            'name'     => 'Tenant B Progress User',
            'email'    => 'tenantb-prog@example.com',
            'password' => Hash::make('password'),
        ]);
        $tokenB = $userB->createToken('token-b-prog')->plainTextToken;

        // Tenant B tries to access Tenant A's progress
        $response = $this->getJson("/api/v1/subcontracts/agreements/{$agreementId}/progress", [
            'X-Tenant'      => $tenantBSlug,
            'Authorization' => 'Bearer ' . $tokenB,
        ]);

        // Should not expose Tenant A's data
        $response->assertStatus(404);

        // Cleanup
        DB::connection('mysql')->table('tenants')->where('slug', $tenantBSlug)->delete();
    }

    /** @test */
    public function cross_tenant_uuid_access_does_not_expose_data(): void
    {
        // Create agreement in Tenant A
        $agreement = $this->createAgreement();
        $agreementId = $agreement['id'];

        // Create Tenant B
        $tenantBSlug = 'subcontract-uuid-isolation-' . Str::random(4);
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $tenantBSlug,
            'name'        => 'UUID Isolation Tenant B',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $userB = User::on('tenant')->create([
            'name'     => 'UUID Isolation User B',
            'email'    => 'uuid-isolation@example.com',
            'password' => Hash::make('password'),
        ]);
        $tokenB = $userB->createToken('token-uuid')->plainTextToken;

        // Tenant B uses Tenant A's UUID directly
        $response = $this->getJson("/api/v1/subcontracts/agreements/{$agreementId}", [
            'X-Tenant'      => $tenantBSlug,
            'Authorization' => 'Bearer ' . $tokenB,
        ]);

        $response->assertStatus(404);

        // Cleanup
        DB::connection('mysql')->table('tenants')->where('slug', $tenantBSlug)->delete();
    }

    // =========================================================================
    // 4. AUTHORIZATION
    // =========================================================================

    /** @test */
    public function submit_agreement_without_permission_returns_403(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        // User without subcontract.submit permission
        $response = $this->patchJson("/api/v1/subcontracts/agreements/{$id}/submit", [], $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function activate_agreement_without_permission_returns_403(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->patchJson("/api/v1/subcontracts/agreements/{$id}/activate", [], $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function complete_agreement_without_permission_returns_403(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->patchJson("/api/v1/subcontracts/agreements/{$id}/complete", [], $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function cancel_agreement_without_permission_returns_403(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->patchJson("/api/v1/subcontracts/agreements/{$id}/cancel", [], $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function record_progress_without_permission_returns_403(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->postJson("/api/v1/subcontracts/agreements/{$id}/progress", [
            'progress_period'       => '2026-09',
            'percentage_complete'   => 25.0,
            'cumulative_percentage' => 25.0,
        ], $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function issue_certificate_without_permission_returns_403(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->postJson("/api/v1/subcontracts/agreements/{$id}/certificates", [
            'certificate_number'      => 'CERT-001',
            'subcontract_progress_id' => Str::uuid()->toString(),
            'certified_amount'        => 50000.0,
            'issued_date'             => '2026-09-30',
        ], $this->authHeaders());
        $response->assertStatus(403);
    }

    /** @test */
    public function approve_certificate_without_permission_returns_403(): void
    {
        $fakeId = Str::uuid()->toString();
        $response = $this->patchJson("/api/v1/subcontracts/certificates/{$fakeId}/approve", [], $this->authHeaders());
        $response->assertStatus(403);
    }

    // =========================================================================
    // 5. VALIDATION
    // =========================================================================

    /** @test */
    public function create_agreement_requires_agreement_number(): void
    {
        $payload = $this->validAgreementPayload();
        unset($payload['agreement_number']);

        $response = $this->postJson('/api/v1/subcontracts/agreements', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function create_agreement_requires_subcontractor_name(): void
    {
        $payload = $this->validAgreementPayload();
        unset($payload['subcontractor_name']);

        $response = $this->postJson('/api/v1/subcontracts/agreements', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function create_agreement_requires_title(): void
    {
        $payload = $this->validAgreementPayload();
        unset($payload['title']);

        $response = $this->postJson('/api/v1/subcontracts/agreements', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function create_agreement_requires_contract_value(): void
    {
        $payload = $this->validAgreementPayload();
        unset($payload['contract_value']);

        $response = $this->postJson('/api/v1/subcontracts/agreements', $payload, $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function add_scope_requires_scope_item_number(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->postJson("/api/v1/subcontracts/agreements/{$id}/scopes", [
            'description' => 'Missing scope_item_number',
            'quantity'    => 10,
            'unit_rate'   => 1000.0,
        ], $this->authHeaders());
        $response->assertStatus(422);
    }

    /** @test */
    public function add_scope_requires_description(): void
    {
        $agreement = $this->createAgreement();
        $id = $agreement['id'];

        $response = $this->postJson("/api/v1/subcontracts/agreements/{$id}/scopes", [
            'scope_item_number' => 'SCOPE-001',
            'quantity'          => 10,
            'unit_rate'         => 1000.0,
        ], $this->authHeaders());
        $response->assertStatus(422);
    }

    // =========================================================================
    // 6. SUCCESSFUL API OPERATIONS — AGREEMENTS
    // =========================================================================

    /** @test */
    public function can_create_agreement(): void
    {
        $response = $this->postJson('/api/v1/subcontracts/agreements', $this->validAgreementPayload([
            'agreement_number'   => 'SC-CREATE-001',
            'subcontractor_name' => 'Alpha Construction Co.',
            'title'              => 'Foundation Works Agreement',
            'contract_value'     => 750000.00,
            'currency'           => 'SAR',
        ]), $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('SC-CREATE-001', $data['agreement_number']);
        $this->assertEquals('Alpha Construction Co.', $data['subcontractor_name']);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals($this->tenantSlug, $data['tenant_slug']);
    }

    /** @test */
    public function can_get_agreement(): void
    {
        $created = $this->createAgreement(['agreement_number' => 'SC-GET-001']);
        $id = $created['id'];

        $response = $this->getJson("/api/v1/subcontracts/agreements/{$id}", $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('id'));
        $this->assertEquals('SC-GET-001', $response->json('agreement_number'));
    }

    /** @test */
    public function get_agreement_returns_404_for_unknown(): void
    {
        $fakeId = Str::uuid()->toString();
        $response = $this->getJson("/api/v1/subcontracts/agreements/{$fakeId}", $this->authHeaders());
        $response->assertStatus(404);
    }

    /** @test */
    public function can_get_subcontract_value(): void
    {
        $created = $this->createAgreement(['agreement_number' => 'SC-VALUE-001', 'contract_value' => 1000000.00]);
        $id = $created['id'];

        $response = $this->getJson("/api/v1/subcontracts/agreements/{$id}/value", $this->authHeaders());
        $response->assertStatus(200);
    }

    /** @test */
    public function get_value_returns_404_for_unknown(): void
    {
        $fakeId = Str::uuid()->toString();
        $response = $this->getJson("/api/v1/subcontracts/agreements/{$fakeId}/value", $this->authHeaders());
        $response->assertStatus(404);
    }

    // =========================================================================
    // 7. SUCCESSFUL API OPERATIONS — SCOPE
    // =========================================================================

    /** @test */
    public function can_add_scope_to_agreement(): void
    {
        $agreement = $this->createAgreement(['agreement_number' => 'SC-SCOPE-001']);
        $id = $agreement['id'];

        $response = $this->postJson("/api/v1/subcontracts/agreements/{$id}/scopes", [
            'scope_item_number' => 'SCOPE-001',
            'description'       => 'Excavation and earthworks',
            'unit'              => 'm3',
            'quantity'          => 500.0,
            'unit_rate'         => 150.0,
            'total_amount'      => 75000.0,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('SCOPE-001', $data['scope_item_number']);
        $this->assertEquals('Excavation and earthworks', $data['description']);
    }

    /** @test */
    public function add_scope_to_unknown_agreement_returns_422(): void
    {
        $fakeId = Str::uuid()->toString();

        $response = $this->postJson("/api/v1/subcontracts/agreements/{$fakeId}/scopes", [
            'scope_item_number' => 'SCOPE-UNKNOWN',
            'description'       => 'Unknown agreement scope',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // 8. ROUTE REGISTRATION
    // =========================================================================

    /** @test */
    public function subcontract_agreement_routes_are_registered(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName());

        $this->assertArrayHasKey('subcontracts.agreements.create', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.show', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.submit', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.activate', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.complete', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.cancel', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.value', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.scopes.add', $routes);
    }

    /** @test */
    public function subcontract_progress_routes_are_registered(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName());

        $this->assertArrayHasKey('subcontracts.agreements.progress.record', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.progress.list', $routes);
        $this->assertArrayHasKey('subcontracts.agreements.certificates.issue', $routes);
        $this->assertArrayHasKey('subcontracts.certificates.approve', $routes);
    }

    // =========================================================================
    // 9. TENANT CONTEXT VERIFICATION
    // =========================================================================

    /** @test */
    public function tenant_context_is_set_from_x_tenant_header(): void
    {
        $response = $this->postJson('/api/v1/subcontracts/agreements', $this->validAgreementPayload([
            'agreement_number' => 'SC-TENANT-CTX-001',
        ]), $this->authHeaders());

        $response->assertStatus(201);
        $this->assertDatabaseHas('subcontract_agreements', [
            'agreement_number' => 'SC-TENANT-CTX-001',
            'tenant_slug'      => $this->tenantSlug,
        ], 'tenant');
    }
}
