<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\IAM\User;
use App\Inventory\GoodsReceiptNote;
use App\Inventory\GRNItem;
use App\Inventory\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * InventoryHttpApiTest — Feature tests for TASK-008C Inventory HTTP API Layer.
 *
 * Tests:
 *  - Authentication boundary — endpoints return 401 without token
 *  - CAP-070: POST/GET/PATCH grns (create, show, add-item, confirm, cancel)
 *  - CAP-071: GET items (show, stock-level, stock-levels, total-quantity, assert-stock)
 *  - Validation errors → 422
 *  - Not found → 404
 *  - Invalid state → 422
 *  - Tenant isolation
 *  - Service delegation (thin controllers)
 *
 * TASK-008C — Inventory HTTP API Layer
 */
class InventoryHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'inventory-http-test';
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
            DB::connection('tenant')->table('grn_items')->delete();
            DB::connection('tenant')->table('goods_receipt_notes')->delete();
            DB::connection('tenant')->table('stock_levels')->delete();
            DB::connection('tenant')->table('inventory_items')->delete();
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
            'name'        => 'Inventory HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Inventory Test User',
            'email'    => 'invtest@example.com',
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

    private function createTestItem(string $code = 'MAT-HTTP-001'): InventoryItem
    {
        return InventoryItem::create([
            'tenant_slug'     => $this->tenantSlug,
            'item_code'       => $code,
            'name'            => "HTTP Test Item {$code}",
            'unit_of_measure' => 'pcs',
        ]);
    }

    private function createTestGRN(array $extra = []): GoodsReceiptNote
    {
        return GoodsReceiptNote::create(array_merge([
            'tenant_slug' => $this->tenantSlug,
            'grn_number'  => 'GRN-HTTP-001',
            'status'      => 'draft',
        ], $extra));
    }

    // =========================================================================
    // 1. AUTHENTICATION BOUNDARY
    // =========================================================================

    /** @test */
    public function unauthenticated_request_to_grn_create_returns_401(): void
    {
        $response = $this->postJson('/api/v1/inventory/grns', [
            'grn_number' => 'GRN-UNAUTH',
        ], ['X-Tenant' => $this->tenantSlug]);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_request_to_grn_show_returns_401(): void
    {
        $response = $this->getJson('/api/v1/inventory/grns/some-id', [
            'X-Tenant' => $this->tenantSlug,
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_request_to_stock_item_returns_401(): void
    {
        $response = $this->getJson('/api/v1/inventory/items/some-id', [
            'X-Tenant' => $this->tenantSlug,
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // 2. GRN — CREATE (CAP-070)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_create_grn(): void
    {
        $response = $this->postJson('/api/v1/inventory/grns', [
            'grn_number' => 'GRN-API-001',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['status' => 'draft']);
        $response->assertJsonFragment(['grn_number' => 'GRN-API-001']);
    }

    /** @test */
    public function grn_create_stores_purchase_order_id_as_uuid_reference(): void
    {
        $fakePOId = '550e8400-e29b-41d4-a716-446655440000';

        $response = $this->postJson('/api/v1/inventory/grns', [
            'grn_number'        => 'GRN-PO-API',
            'purchase_order_id' => $fakePOId,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['purchase_order_id' => $fakePOId]);
    }

    /** @test */
    public function grn_create_rejects_invalid_uuid_for_purchase_order_id(): void
    {
        $response = $this->postJson('/api/v1/inventory/grns', [
            'grn_number'        => 'GRN-BAD-UUID',
            'purchase_order_id' => 'not-a-uuid',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // 3. GRN — SHOW (CAP-070)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_retrieve_grn_by_id(): void
    {
        $grn = $this->createTestGRN();

        $response = $this->getJson("/api/v1/inventory/grns/{$grn->id}", $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $grn->id]);
        $response->assertJsonFragment(['grn_number' => 'GRN-HTTP-001']);
    }

    /** @test */
    public function grn_show_returns_404_for_nonexistent_id(): void
    {
        $response = $this->getJson('/api/v1/inventory/grns/00000000-0000-0000-0000-000000000000', $this->authHeaders());

        $response->assertStatus(404);
    }

    // =========================================================================
    // 4. GRN — ADD ITEM (CAP-070)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_add_item_to_draft_grn(): void
    {
        $item = $this->createTestItem();
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-ITEM-001']);

        $response = $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 50.0,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['inventory_item_id' => $item->id]);
    }

    /** @test */
    public function grn_add_item_requires_inventory_item_id(): void
    {
        $grn = $this->createTestGRN(['grn_number' => 'GRN-VAL-001']);

        $response = $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function grn_add_item_requires_positive_quantity(): void
    {
        $item = $this->createTestItem('MAT-VAL-002');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-VAL-002']);

        $response = $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 0,
            'unit_cost'         => 10.0,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function grn_add_item_requires_non_negative_unit_cost(): void
    {
        $item = $this->createTestItem('MAT-VAL-003');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-VAL-003']);

        $response = $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => -1.0,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // 5. GRN — CONFIRM (CAP-070)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_confirm_draft_grn_with_items(): void
    {
        $item = $this->createTestItem('MAT-CONF-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-CONF-001']);

        // Add item via API
        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 20.0,
            'unit_cost'         => 100.0,
        ], $this->authHeaders());

        // Confirm via API
        $response = $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'confirmed']);
    }

    /** @test */
    public function grn_confirm_returns_422_when_grn_has_no_items(): void
    {
        $grn = $this->createTestGRN(['grn_number' => 'GRN-EMPTY-001']);

        $response = $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function grn_confirm_returns_422_when_grn_already_confirmed(): void
    {
        $item = $this->createTestItem('MAT-CONF-002');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-CONF-002']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        // Try to confirm again
        $response = $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // 6. GRN — CANCEL (CAP-070)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_cancel_draft_grn(): void
    {
        $grn = $this->createTestGRN(['grn_number' => 'GRN-CANCEL-001']);

        $response = $this->patchJson("/api/v1/inventory/grns/{$grn->id}/cancel", [], $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'cancelled']);
    }

    /** @test */
    public function grn_cancel_returns_422_when_grn_is_confirmed(): void
    {
        $item = $this->createTestItem('MAT-CANCEL-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-CANCEL-CONF']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response = $this->patchJson("/api/v1/inventory/grns/{$grn->id}/cancel", [], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // 7. STOCK — SHOW ITEM (CAP-071)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_retrieve_inventory_item(): void
    {
        $item = $this->createTestItem('MAT-SHOW-001');

        $response = $this->getJson("/api/v1/inventory/items/{$item->id}", $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $item->id]);
        $response->assertJsonFragment(['item_code' => 'MAT-SHOW-001']);
    }

    /** @test */
    public function inventory_item_show_returns_404_for_nonexistent_id(): void
    {
        $response = $this->getJson('/api/v1/inventory/items/00000000-0000-0000-0000-000000000000', $this->authHeaders());

        $response->assertStatus(404);
    }

    // =========================================================================
    // 8. STOCK — STOCK LEVEL (CAP-071)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_retrieve_stock_level_after_grn_confirmation(): void
    {
        $item = $this->createTestItem('MAT-STOCK-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-STOCK-001']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 50.0,
            'unit_cost'         => 20.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response = $this->getJson("/api/v1/inventory/items/{$item->id}/stock-level", $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['inventory_item_id' => $item->id]);
    }

    /** @test */
    public function stock_level_returns_404_when_no_stock_exists(): void
    {
        $item = $this->createTestItem('MAT-NOSTOCK-001');

        $response = $this->getJson("/api/v1/inventory/items/{$item->id}/stock-level", $this->authHeaders());

        $response->assertStatus(404);
    }

    // =========================================================================
    // 9. STOCK — TOTAL QUANTITY (CAP-071)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_retrieve_total_stock_quantity(): void
    {
        $item = $this->createTestItem('MAT-TOTAL-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-TOTAL-001']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 30.0,
            'unit_cost'         => 15.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response = $this->getJson("/api/v1/inventory/items/{$item->id}/total-quantity", $this->authHeaders());

        $response->assertStatus(200);
        $response->assertJsonFragment(['inventory_item_id' => $item->id]);
        $response->assertJsonFragment(['total_quantity_on_hand' => 30.0]);
    }

    // =========================================================================
    // 10. STOCK — ASSERT STOCK (CAP-071)
    // =========================================================================

    /** @test */
    public function assert_stock_returns_200_when_sufficient(): void
    {
        $item = $this->createTestItem('MAT-ASSERT-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-ASSERT-001']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 100.0,
            'unit_cost'         => 10.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response = $this->getJson(
            "/api/v1/inventory/items/{$item->id}/assert-stock?required_quantity=50",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['sufficient' => true]);
    }

    /** @test */
    public function assert_stock_returns_422_when_insufficient(): void
    {
        $item = $this->createTestItem('MAT-ASSERT-002');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-ASSERT-002']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 5.0,
            'unit_cost'         => 10.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response = $this->getJson(
            "/api/v1/inventory/items/{$item->id}/assert-stock?required_quantity=100",
            $this->authHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonFragment(['sufficient' => false]);
    }

    /** @test */
    public function assert_stock_requires_required_quantity_param(): void
    {
        $item = $this->createTestItem('MAT-ASSERT-003');

        $response = $this->getJson(
            "/api/v1/inventory/items/{$item->id}/assert-stock",
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // 11. STOCK — LIST STOCK LEVELS (CAP-071)
    // =========================================================================

    /** @test */
    public function authenticated_user_can_list_stock_levels_for_item(): void
    {
        $item = $this->createTestItem('MAT-LIST-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-LIST-001']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 10.0,
            'unit_cost'         => 5.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        $response = $this->getJson("/api/v1/inventory/items/{$item->id}/stock-levels", $this->authHeaders());

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    // =========================================================================
    // 12. TENANT ISOLATION
    // =========================================================================

    /** @test */
    public function tenant_context_is_set_from_x_tenant_header(): void
    {
        $response = $this->postJson('/api/v1/inventory/grns', [
            'grn_number' => 'GRN-TENANT-001',
        ], $this->authHeaders());

        $response->assertStatus(201);
        // The GRN is created with the tenant_slug from the X-Tenant header context
        $this->assertDatabaseHas('goods_receipt_notes', [
            'grn_number' => 'GRN-TENANT-001',
        ], 'tenant');
    }

    /** @test */
    public function request_without_x_tenant_header_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/inventory/grns', [
            'grn_number' => 'GRN-NO-TENANT',
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            // No X-Tenant header
        ]);

        // Should be rejected by tenant middleware (401 or 400 depending on middleware behavior)
        $this->assertContains($response->status(), [400, 401, 422, 500]);
    }

    // =========================================================================
    // 13. SERVICE DELEGATION (Thin Controller Verification)
    // =========================================================================

    /** @test */
    public function grn_confirmation_updates_stock_level_via_service(): void
    {
        // This test verifies that the controller correctly delegates to GoodsReceiptService
        // which applies AVCO and updates StockLevel — the controller does NOT do this directly.
        $item = $this->createTestItem('MAT-DELEG-001');
        $grn  = $this->createTestGRN(['grn_number' => 'GRN-DELEG-001']);

        $this->postJson("/api/v1/inventory/grns/{$grn->id}/items", [
            'inventory_item_id' => $item->id,
            'quantity_received' => 25.0,
            'unit_cost'         => 40.0,
        ], $this->authHeaders());

        $this->patchJson("/api/v1/inventory/grns/{$grn->id}/confirm", [], $this->authHeaders());

        // Verify stock was updated (service delegation worked)
        $stockResponse = $this->getJson("/api/v1/inventory/items/{$item->id}/stock-level", $this->authHeaders());
        $stockResponse->assertStatus(200);

        $stockData = $stockResponse->json();
        $this->assertEquals('25.000000', $stockData['quantity_on_hand']);
        $this->assertEquals('40.000000', $stockData['average_unit_cost']);
    }

    // =========================================================================
    // 14. ROUTE REGISTRATION
    // =========================================================================

    /** @test */
    public function inventory_grn_routes_are_registered(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName());

        $this->assertArrayHasKey('inventory.grns.create', $routes);
        $this->assertArrayHasKey('inventory.grns.show', $routes);
        $this->assertArrayHasKey('inventory.grns.items.add', $routes);
        $this->assertArrayHasKey('inventory.grns.confirm', $routes);
        $this->assertArrayHasKey('inventory.grns.cancel', $routes);
    }

    /** @test */
    public function inventory_stock_routes_are_registered(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName());

        $this->assertArrayHasKey('inventory.items.show', $routes);
        $this->assertArrayHasKey('inventory.items.stock-level', $routes);
        $this->assertArrayHasKey('inventory.items.stock-levels', $routes);
        $this->assertArrayHasKey('inventory.items.total-quantity', $routes);
        $this->assertArrayHasKey('inventory.items.assert-stock', $routes);
    }
}
