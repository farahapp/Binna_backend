<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ProcurementHttpApiTest — Feature tests for TASK-007C Procurement HTTP API Layer.
 *
 * Tests:
 *  - Authentication boundary — endpoints return 401 without token
 *  - CAP-061: POST/GET/PATCH purchase-requests (create, show, submit, cancel)
 *  - CAP-062: POST/GET/PATCH rfqs (create, show, send, close, quotations)
 *  - CAP-063: POST/GET/PATCH purchase-orders (create, show, add-item, submit)
 *  - Validation errors → 422
 *  - Not found → 404
 *
 * TASK-007C — Procurement HTTP API Layer
 */
class ProcurementHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'procurement-http-test';
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
            DB::connection('tenant')->table('po_items')->delete();
            DB::connection('tenant')->table('supplier_quotations')->delete();
            DB::connection('tenant')->table('purchase_orders')->delete();
            DB::connection('tenant')->table('rfqs')->delete();
            DB::connection('tenant')->table('purchase_requests')->delete();
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
            'name'        => 'Procurement HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Procurement Test User',
            'email'    => 'proctest@example.com',
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

    private function createTestSupplier(): string
    {
        $supplierId = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('tenant')->table('tenant_suppliers')->insert([
            'id'            => $supplierId,
            'tenant_slug'   => $this->tenantSlug,
            'name'          => 'Test Supplier Co.',
            'contact_email' => 'supplier@example.com',
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        return $supplierId;
    }

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/procurement/purchase-requests', [
            'title' => 'Test PR',
        ], ['X-Tenant' => $this->tenantSlug]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // CAP-061 — PURCHASE REQUESTS
    // =========================================================================

    /** @test */
    public function can_create_purchase_request(): void
    {
        $response = $this->postJson('/api/v1/procurement/purchase-requests', [
            'title'       => 'Office Supplies Q1',
            'description' => 'Stationery and supplies',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('Office Supplies Q1', $data['title']);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals($this->tenantSlug, $data['tenant_slug']);
    }

    /** @test */
    public function create_purchase_request_requires_title(): void
    {
        $response = $this->postJson('/api/v1/procurement/purchase-requests', [
            'description' => 'Missing title',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function can_get_purchase_request(): void
    {
        $create = $this->postJson('/api/v1/procurement/purchase-requests', [
            'title' => 'Get Test PR',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->getJson("/api/v1/procurement/purchase-requests/{$id}", $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('id'));
    }

    /** @test */
    public function get_purchase_request_returns_404_for_unknown(): void
    {
        $fakeId = \Illuminate\Support\Str::uuid()->toString();
        $response = $this->getJson("/api/v1/procurement/purchase-requests/{$fakeId}", $this->authHeaders());
        $response->assertStatus(404);
    }

    /** @test */
    public function can_submit_purchase_request(): void
    {
        $create = $this->postJson('/api/v1/procurement/purchase-requests', [
            'title' => 'Submit Test PR',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->patchJson("/api/v1/procurement/purchase-requests/{$id}/submit", [], $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals('submitted', $response->json('status'));
    }

    /** @test */
    public function can_cancel_purchase_request(): void
    {
        $create = $this->postJson('/api/v1/procurement/purchase-requests', [
            'title' => 'Cancel Test PR',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->patchJson("/api/v1/procurement/purchase-requests/{$id}/cancel", [], $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('status'));
    }

    // =========================================================================
    // CAP-062 — RFQs & QUOTATIONS
    // =========================================================================

    /** @test */
    public function can_create_rfq(): void
    {
        $response = $this->postJson('/api/v1/procurement/rfqs', [
            'title'       => 'Construction Materials RFQ',
            'description' => 'Steel and cement',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('Construction Materials RFQ', $data['title']);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals($this->tenantSlug, $data['tenant_slug']);
    }

    /** @test */
    public function create_rfq_requires_title(): void
    {
        $response = $this->postJson('/api/v1/procurement/rfqs', [
            'description' => 'Missing title',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function can_get_rfq(): void
    {
        $create = $this->postJson('/api/v1/procurement/rfqs', [
            'title' => 'Get Test RFQ',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->getJson("/api/v1/procurement/rfqs/{$id}", $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('id'));
    }

    /** @test */
    public function get_rfq_returns_404_for_unknown(): void
    {
        $fakeId = \Illuminate\Support\Str::uuid()->toString();
        $response = $this->getJson("/api/v1/procurement/rfqs/{$fakeId}", $this->authHeaders());
        $response->assertStatus(404);
    }

    /** @test */
    public function can_send_rfq(): void
    {
        $create = $this->postJson('/api/v1/procurement/rfqs', [
            'title' => 'Send Test RFQ',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->patchJson("/api/v1/procurement/rfqs/{$id}/send", [], $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals('sent', $response->json('status'));
    }

    /** @test */
    public function can_close_rfq(): void
    {
        $create = $this->postJson('/api/v1/procurement/rfqs', [
            'title' => 'Close Test RFQ',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $rfqId = $create->json('id');

        $this->patchJson("/api/v1/procurement/rfqs/{$rfqId}/send", [], $this->authHeaders())->assertStatus(200);

        $response = $this->patchJson("/api/v1/procurement/rfqs/{$rfqId}/close", [], $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals('closed', $response->json('status'));
    }

    /** @test */
    public function can_submit_quotation_to_sent_rfq(): void
    {
        $supplierId = $this->createTestSupplier();

        $create = $this->postJson('/api/v1/procurement/rfqs', [
            'title' => 'Quotation Test RFQ',
        ], $this->authHeaders());
        $create->assertStatus(201);
        $rfqId = $create->json('id');

        $this->patchJson("/api/v1/procurement/rfqs/{$rfqId}/send", [], $this->authHeaders())->assertStatus(200);

        $response = $this->postJson("/api/v1/procurement/rfqs/{$rfqId}/quotations", [
            'supplier_id'  => $supplierId,
            'total_amount' => 15000.00,
            'currency'     => 'SAR',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $this->assertEquals($rfqId, $response->json('rfq_id'));
        $this->assertEquals($supplierId, $response->json('supplier_id'));
    }

    /** @test */
    public function can_compare_quotations(): void
    {
        $supplierId = $this->createTestSupplier();

        $create = $this->postJson('/api/v1/procurement/rfqs', [
            'title' => 'Compare Test RFQ',
        ], $this->authHeaders());
        $rfqId = $create->json('id');

        $this->patchJson("/api/v1/procurement/rfqs/{$rfqId}/send", [], $this->authHeaders());
        $this->postJson("/api/v1/procurement/rfqs/{$rfqId}/quotations", [
            'supplier_id'  => $supplierId,
            'total_amount' => 12000.00,
        ], $this->authHeaders());

        $response = $this->getJson("/api/v1/procurement/rfqs/{$rfqId}/quotations/compare", $this->authHeaders());
        $response->assertStatus(200);
        $this->assertIsArray($response->json());
        $this->assertCount(1, $response->json());
    }

    // =========================================================================
    // CAP-063 — PURCHASE ORDERS
    // =========================================================================

    /** @test */
    public function can_create_purchase_order(): void
    {
        $supplierId = $this->createTestSupplier();

        $response = $this->postJson('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplierId,
            'currency'    => 'SAR',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals($supplierId, $data['supplier_id']);
        $this->assertEquals('draft', $data['status']);
        $this->assertEquals($this->tenantSlug, $data['tenant_slug']);
    }

    /** @test */
    public function create_purchase_order_requires_supplier_id(): void
    {
        $response = $this->postJson('/api/v1/procurement/purchase-orders', [
            'currency' => 'SAR',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function can_get_purchase_order(): void
    {
        $supplierId = $this->createTestSupplier();

        $create = $this->postJson('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplierId,
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->getJson("/api/v1/procurement/purchase-orders/{$id}", $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('id'));
    }

    /** @test */
    public function get_purchase_order_returns_404_for_unknown(): void
    {
        $fakeId = \Illuminate\Support\Str::uuid()->toString();
        $response = $this->getJson("/api/v1/procurement/purchase-orders/{$fakeId}", $this->authHeaders());
        $response->assertStatus(404);
    }

    /** @test */
    public function can_add_item_to_draft_purchase_order(): void
    {
        $supplierId = $this->createTestSupplier();

        $create = $this->postJson('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplierId,
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->postJson("/api/v1/procurement/purchase-orders/{$id}/items", [
            'description' => 'Cement bags 50kg',
            'quantity'    => 100,
            'unit'        => 'bag',
            'unit_price'  => 25.00,
            'total_price' => 2500.00,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $this->assertEquals('Cement bags 50kg', $response->json('description'));
        $this->assertEquals($id, $response->json('purchase_order_id'));
    }

    /** @test */
    public function can_submit_purchase_order(): void
    {
        $supplierId = $this->createTestSupplier();

        $create = $this->postJson('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplierId,
        ], $this->authHeaders());
        $create->assertStatus(201);
        $id = $create->json('id');

        $response = $this->patchJson("/api/v1/procurement/purchase-orders/{$id}/submit", [], $this->authHeaders());
        $response->assertStatus(200);
        $this->assertEquals('submitted', $response->json('status'));
    }
}
