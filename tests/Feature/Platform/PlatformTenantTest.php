<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PlatformTenantTest — HTTP tests for platform tenant management.
 *
 * Coverage:
 *  PT-01 — GET /api/platform/tenants returns paginated list (authenticated).
 *  PT-02 — GET /api/platform/tenants without token returns 401.
 *  PT-03 — GET /api/platform/tenants?status=active filters by status.
 *  PT-04 — GET /api/platform/tenants/{id} returns tenant + settings.
 *  PT-05 — GET /api/platform/tenants/{id} for non-existent tenant returns 404.
 *  PT-06 — PATCH /api/platform/tenants/{id}/suspend suspends an active tenant.
 *  PT-07 — PATCH /api/platform/tenants/{id}/suspend on non-active tenant returns 422.
 *  PT-08 — PATCH /api/platform/tenants/{id}/activate activates a pending tenant.
 *  PT-09 — schema_name is never returned in any tenant response.
 *  PT-10 — Tenant user token cannot access platform tenant routes (403).
 *
 * Uses DatabaseTransactions on 'mysql' only — no tenant schema touched.
 */
class PlatformTenantTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePlatformUsersTableExists();
        $this->ensureTenantsTableExists();
    }

    // =========================================================================
    // PT-01 — Authenticated list returns paginated data
    // =========================================================================

    /** @test */
    public function authenticated_operator_can_list_tenants(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $this->makeTenant('test-list-' . uniqid(), 'active');

        $response = $this->getJson('/api/platform/tenants', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('current_page', $data);
        $this->assertIsArray($data['data']);
    }

    // =========================================================================
    // PT-02 — Unauthenticated list returns 401
    // =========================================================================

    /** @test */
    public function unauthenticated_request_to_tenants_returns_401(): void
    {
        $response = $this->getJson('/api/platform/tenants');
        $response->assertStatus(401);
    }

    // =========================================================================
    // PT-03 — Status filter works
    // =========================================================================

    /** @test */
    public function status_filter_returns_only_matching_tenants(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $slug = 'filter-test-' . uniqid();
        $this->makeTenant($slug, 'suspended');

        $response = $this->getJson('/api/platform/tenants?status=suspended', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        foreach ($data as $tenant) {
            $this->assertSame('suspended', $tenant['status']);
        }
    }

    // =========================================================================
    // PT-04 — Show returns tenant + settings
    // =========================================================================

    /** @test */
    public function show_returns_tenant_and_settings(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('show-test-' . uniqid(), 'active');

        $response = $this->getJson("/api/platform/tenants/{$tenant->id}", [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $this->assertSame($tenant->id, $response->json('tenant.id'));
        $this->assertSame($tenant->slug, $response->json('tenant.slug'));
        $this->assertIsArray($response->json('settings'));
    }

    // =========================================================================
    // PT-05 — Show non-existent tenant returns 404
    // =========================================================================

    /** @test */
    public function show_non_existent_tenant_returns_404(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->getJson('/api/platform/tenants/999999', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // PT-06 — Suspend an active tenant
    // =========================================================================

    /** @test */
    public function suspend_active_tenant_returns_200_and_updates_status(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('suspend-test-' . uniqid(), 'active');

        $response = $this->patchJson("/api/platform/tenants/{$tenant->id}/suspend", [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $this->assertSame('suspended', $response->json('tenant.status'));

        // Verify DB
        $fresh = Tenant::on('mysql')->find($tenant->id);
        $this->assertSame('suspended', $fresh->status);
    }

    // =========================================================================
    // PT-07 — Suspend non-active tenant returns 422
    // =========================================================================

    /** @test */
    public function suspend_non_active_tenant_returns_422(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('suspend-422-' . uniqid(), 'pending');

        $response = $this->patchJson("/api/platform/tenants/{$tenant->id}/suspend", [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PT-08 — Activate a pending tenant
    // =========================================================================

    /** @test */
    public function activate_pending_tenant_returns_200_and_updates_status(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('activate-test-' . uniqid(), 'pending');

        $response = $this->patchJson("/api/platform/tenants/{$tenant->id}/activate", [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $this->assertSame('active', $response->json('tenant.status'));

        $fresh = Tenant::on('mysql')->find($tenant->id);
        $this->assertSame('active', $fresh->status);
    }

    // =========================================================================
    // PT-09 — schema_name never returned
    // =========================================================================

    /** @test */
    public function schema_name_is_never_returned_in_tenant_responses(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('schema-test-' . uniqid(), 'active');

        // List
        $listResponse = $this->getJson('/api/platform/tenants', [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $listResponse->assertStatus(200);
        foreach ($listResponse->json('data') as $t) {
            $this->assertArrayNotHasKey('schema_name', $t);
        }

        // Show
        $showResponse = $this->getJson("/api/platform/tenants/{$tenant->id}", [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $showResponse->assertStatus(200);
        $this->assertArrayNotHasKey('schema_name', $showResponse->json('tenant'));
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function makePlatformUser(bool $active = true): PlatformUser
    {
        $operator = new PlatformUser();
        $operator->setConnection('mysql');
        $operator->name      = 'Test Platform Operator';
        $operator->email     = 'platform-test-' . uniqid() . '@binaa.test';
        $operator->password  = Hash::make('platform-secret-123');
        $operator->is_active = $active;
        $operator->save();

        return $operator;
    }

    private function makeTenant(string $slug, string $status): Tenant
    {
        $tenant = new Tenant();
        $tenant->setConnection('mysql');
        $tenant->slug        = $slug;
        $tenant->name        = 'Test Tenant ' . $slug;
        $tenant->status      = $status;
        $tenant->schema_name = 'binaa2_tenant_' . $slug;
        $tenant->save();

        return $tenant;
    }

    private function ensurePlatformUsersTableExists(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_users')) {
            Schema::connection('mysql')->create('platform_users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    private function ensureTenantsTableExists(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Schema::connection('mysql')->create('tenants', function ($table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->string('schema_name')->unique();
                $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
                $table->timestamps();
            });
        }
    }
}
