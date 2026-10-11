<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\Tenant;
use App\Foundation\TenantContext;
use App\Foundation\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature tests covering TASK-001A Verification Point 2:
 *   VP2 — X-Tenant header resolves the correct schema.
 *
 * Also covers the security invariant that schema_name is never read from
 * the request (VP3 middleware side).
 *
 * Uses a mock TenantContextResolver to avoid requiring a live DB for
 * middleware-level tests.
 */
class TenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register a test route protected by the tenant middleware.
        Route::middleware('tenant')->get('/test-tenant-route', function () {
            /** @var TenantContext $ctx */
            $ctx = app(TenantContext::class);

            return response()->json([
                'slug' => $ctx->slug,
                'schema_name' => $ctx->schemaName,
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // VP2 — X-Tenant header resolves the correct schema
    // -------------------------------------------------------------------------

    /** @test */
    public function missing_x_tenant_header_returns_400(): void
    {
        $response = $this->getJson('/test-tenant-route');

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'X-Tenant header is required.']);
    }

    /** @test */
    public function unknown_tenant_slug_returns_404(): void
    {
        $response = $this->getJson('/test-tenant-route', ['X-Tenant' => 'nonexistent-slug']);

        $response->assertStatus(404);
        $response->assertJsonFragment(['error' => 'Tenant not found.']);
    }

    /** @test */
    public function inactive_tenant_returns_403(): void
    {
        // Create a pending tenant directly (no provisioning).
        Tenant::create([
            'slug' => 'pending-tenant',
            'name' => 'Pending Tenant',
            'status' => 'pending',
        ]);
        // Set schema_name directly (server-side).
        Tenant::where('slug', 'pending-tenant')->update([
            'schema_name' => Tenant::deriveSchemaName('pending-tenant'),
        ]);

        $response = $this->getJson('/test-tenant-route', ['X-Tenant' => 'pending-tenant']);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'Tenant is not active.']);
    }

    /** @test */
    public function valid_active_tenant_resolves_context_and_returns_200(): void
    {
        // Create an active tenant directly.
        $tenant = Tenant::create([
            'slug' => 'active-tenant',
            'name' => 'Active Tenant',
            'status' => 'active',
        ]);
        $tenant->schema_name = Tenant::deriveSchemaName('active-tenant');
        $tenant->save();

        $response = $this->getJson('/test-tenant-route', ['X-Tenant' => 'active-tenant']);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'slug' => 'active-tenant',
            'schema_name' => 'binaa2_tenant_active_tenant',
        ]);
    }

    /** @test */
    public function middleware_resolves_schema_name_from_db_not_from_header(): void
    {
        // Create tenant with a known schema_name.
        $tenant = Tenant::create([
            'slug' => 'db-resolved',
            'name' => 'DB Resolved',
            'status' => 'active',
        ]);
        $tenant->schema_name = 'binaa2_tenant_db_resolved';
        $tenant->save();

        // Even if the client sends a fake schema_name in a custom header, it is ignored.
        $response = $this->getJson('/test-tenant-route', [
            'X-Tenant' => 'db-resolved',
            'X-Schema-Name' => 'injected_schema', // must be ignored
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['schema_name' => 'binaa2_tenant_db_resolved']);
    }
}
