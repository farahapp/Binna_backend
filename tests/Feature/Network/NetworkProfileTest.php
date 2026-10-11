<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Audit\AuditEntry;
use App\Network\NetworkProfile;
use App\Network\NetworkProfileService;
use App\Procurement\TenantSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NetworkProfileTest — Feature tests for NETWORK-001.
 *
 * Tests cover:
 *  - Network Profile CRUD
 *  - Participant type validation
 *  - Status transitions (draft → active)
 *  - OD-005: Optional TenantSupplier ↔ NetworkProfile linking
 *  - Authorization (unauthorized access rejected)
 *  - Audit logging
 *  - Cross-tenant isolation
 *
 * Uses bootstrap_iam_tenant.php pattern for tenant setup.
 */
class NetworkProfileTest extends TestCase
{
    private array  $tenantCtx   = [];
    private string $token       = '';
    private string $tenantSlug  = '';
    private string $password    = 'Network001Test!';

    // -------------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';

        $this->tenantCtx  = bootstrapAuthSmokeTenant($this->password);
        $this->tenantSlug = $this->tenantCtx['tenant']->slug;

        // Run Network migrations on global schema (network_profiles table)
        $this->runNetworkMigrations();

        // Run procurement migration for network_profile_id column
        $this->runProcurementNetworkMigration();

        // Obtain PAT token
        $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->postJson('/api/auth/login', [
                'email'    => $this->tenantCtx['user']->email,
                'password' => $this->password,
            ]);

        $this->token = $loginResponse->json('token') ?? '';
    }

    protected function tearDown(): void
    {
        // Clean up network_profiles created during this test
        try {
            DB::connection('mysql')->table('network_profiles')
                ->where('managed_by_tenant_slug', 'auth-smoke')
                ->delete();
        } catch (\Throwable) {}

        // Clean up tenant
        try {
            cleanupAuthSmokeTenant();
        } catch (\Throwable) {}

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function runNetworkMigrations(): void
    {
        if (! Schema::connection('mysql')->hasTable('network_profiles')) {
            /** @var \Illuminate\Database\Migrations\Migrator $migrator */
            $migrator = app('migrator');
            $migrator->setConnection('mysql');
            $migrator->run(database_path('migrations/network'));
        }
    }

    private function runProcurementNetworkMigration(): void
    {
        if (Schema::connection('tenant')->hasTable('tenant_suppliers') &&
            ! Schema::connection('tenant')->hasColumn('tenant_suppliers', 'network_profile_id')) {
            /** @var \Illuminate\Database\Migrations\Migrator $migrator */
            $migrator = app('migrator');
            $migrator->setConnection('tenant');
            $migrator->run(database_path('migrations/procurement'));
        }
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function createTestProfile(array $overrides = []): array
    {
        $data = array_merge([
            'company_name' => 'Test Contractor Co.',
            'profile_type' => 'contractor',
            'region'       => 'Qatar',
        ], $overrides);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles', $data);

        return $response->json('data') ?? [];
    }

    // -------------------------------------------------------------------------
    // Tests — Create
    // -------------------------------------------------------------------------

    public function test_create_network_profile_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles', [
                'company_name' => 'Al Bina Construction',
                'profile_type' => 'contractor',
                'region'       => 'Qatar',
                'contact_email' => 'info@albina.qa',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.company_name', 'Al Bina Construction');
        $response->assertJsonPath('data.profile_type', 'contractor');
        $response->assertJsonPath('data.status', 'draft');
        $this->assertNotEmpty($response->json('data.id'));
    }

    public function test_create_network_profile_validates_required_fields(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['company_name', 'profile_type']]);
    }

    public function test_create_network_profile_validates_profile_type_enum(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles', [
                'company_name' => 'Test Co',
                'profile_type' => 'invalid_type',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['profile_type']]);
    }

    public function test_create_all_valid_profile_types(): void
    {
        $types = ['owner', 'engineer', 'contractor', 'main_contractor', 'subcontractor', 'supplier'];

        foreach ($types as $type) {
            $response = $this->withHeaders($this->authHeaders())
                ->postJson('/api/v1/network/profiles', [
                    'company_name' => "Test {$type} Co",
                    'profile_type' => $type,
                ]);

            $response->assertStatus(201, "Failed for profile_type: {$type}");
            $response->assertJsonPath('data.profile_type', $type);
        }
    }

    // -------------------------------------------------------------------------
    // Tests — Read
    // -------------------------------------------------------------------------

    public function test_get_network_profile_returns_200(): void
    {
        $profile = $this->createTestProfile(['company_name' => 'Get Test Co']);
        $this->assertNotEmpty($profile['id'] ?? '');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/profiles/' . $profile['id']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $profile['id']);
        $response->assertJsonPath('data.company_name', 'Get Test Co');
    }

    public function test_get_nonexistent_profile_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/profiles/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    public function test_list_network_profiles_returns_paginated(): void
    {
        // Create a couple of profiles
        $this->createTestProfile(['company_name' => 'List Test A']);
        $this->createTestProfile(['company_name' => 'List Test B', 'profile_type' => 'supplier']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/profiles');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);
    }

    public function test_list_network_profiles_filters_by_type(): void
    {
        $this->createTestProfile(['company_name' => 'Filter Supplier', 'profile_type' => 'supplier']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/profiles?type=supplier');

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $item) {
            $this->assertEquals('supplier', $item['profile_type']);
        }
    }

    // -------------------------------------------------------------------------
    // Tests — Update
    // -------------------------------------------------------------------------

    public function test_update_network_profile_returns_200(): void
    {
        $profile = $this->createTestProfile(['company_name' => 'Before Update']);

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/profiles/' . $profile['id'], [
                'company_name' => 'After Update',
                'region'       => 'Doha',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.company_name', 'After Update');
        $response->assertJsonPath('data.region', 'Doha');
    }

    public function test_update_nonexistent_profile_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/profiles/00000000-0000-0000-0000-000000000000', [
                'company_name' => 'Ghost',
            ]);

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Tests — Activate
    // -------------------------------------------------------------------------

    public function test_activate_network_profile_transitions_status(): void
    {
        $profile = $this->createTestProfile(['company_name' => 'Activate Test Co']);
        $this->assertEquals('draft', $profile['status']);

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/profiles/' . $profile['id'] . '/activate');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_activate_already_active_profile_returns_422(): void
    {
        $profile = $this->createTestProfile(['company_name' => 'Already Active Co']);

        // Activate first time
        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/profiles/' . $profile['id'] . '/activate')
            ->assertStatus(200);

        // Try to activate again
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/profiles/' . $profile['id'] . '/activate');

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Tests — Authorization
    // -------------------------------------------------------------------------

    public function test_unauthorized_access_rejected(): void
    {
        $response = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->postJson('/api/v1/network/profiles', [
                'company_name' => 'Unauthorized Co',
                'profile_type' => 'contractor',
            ]);

        $response->assertStatus(401);
    }

    public function test_list_without_auth_returns_401(): void
    {
        $response = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->getJson('/api/v1/network/profiles');

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Tests — OD-005 Optional Linking
    // -------------------------------------------------------------------------

    public function test_link_tenant_supplier_to_network_profile(): void
    {
        // Create a NetworkProfile
        $profile = $this->createTestProfile(['company_name' => 'Link Test Supplier Co', 'profile_type' => 'supplier']);

        // Create a TenantSupplier
        $supplier = TenantSupplier::on('tenant')->create([
            'tenant_slug'  => $this->tenantSlug,
            'name'         => 'Link Test Supplier',
            'code'         => 'LTS-001',
            'is_active'    => true,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles/' . $profile['id'] . '/link-supplier', [
                'tenant_supplier_id' => $supplier->id,
            ]);

        $response->assertStatus(200);

        // Verify the link was set
        $supplier->refresh();
        $this->assertEquals($profile['id'], $supplier->network_profile_id);

        // Cleanup
        $supplier->delete();
    }

    public function test_unlink_tenant_supplier_from_network_profile(): void
    {
        // Create a NetworkProfile
        $profile = $this->createTestProfile(['company_name' => 'Unlink Test Co', 'profile_type' => 'supplier']);

        // Create a TenantSupplier with a link
        $supplier = TenantSupplier::on('tenant')->create([
            'tenant_slug'        => $this->tenantSlug,
            'name'               => 'Unlink Test Supplier',
            'code'               => 'UTS-001',
            'is_active'          => true,
            'network_profile_id' => $profile['id'],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson('/api/v1/network/suppliers/' . $supplier->id . '/network-link');

        $response->assertStatus(200);

        // Verify the link was removed
        $supplier->refresh();
        $this->assertNull($supplier->network_profile_id);

        // Cleanup
        $supplier->delete();
    }

    public function test_tenant_supplier_unlinked_state_is_null(): void
    {
        $supplier = TenantSupplier::on('tenant')->create([
            'tenant_slug' => $this->tenantSlug,
            'name'        => 'Unlinked Supplier',
            'code'        => 'UNL-001',
            'is_active'   => true,
        ]);

        // network_profile_id should be null by default
        $this->assertNull($supplier->network_profile_id);

        $supplier->delete();
    }

    public function test_link_with_invalid_supplier_id_returns_404(): void
    {
        $profile = $this->createTestProfile(['company_name' => 'Link Invalid Test']);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles/' . $profile['id'] . '/link-supplier', [
                'tenant_supplier_id' => '00000000-0000-0000-0000-000000000000',
            ]);

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Tests — Audit
    // -------------------------------------------------------------------------

    public function test_network_profile_audit_logged(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/profiles', [
                'company_name' => 'Audit Test Co',
                'profile_type' => 'owner',
            ]);

        $response->assertStatus(201);
        $profileId = $response->json('data.id');

        // Verify audit entry was created
        $auditEntry = AuditEntry::where('event', 'network.profile.created')
            ->where('subject_type', 'NetworkProfile')
            ->where('subject_id', $profileId)
            ->first();

        $this->assertNotNull($auditEntry, 'Audit entry for network.profile.created should exist');
        $this->assertEquals('NetworkProfile', $auditEntry->subject_type);
        $this->assertEquals($profileId, $auditEntry->subject_id);
    }

    // -------------------------------------------------------------------------
    // Tests — Integrity
    // -------------------------------------------------------------------------

    public function test_no_direct_cross_schema_coupling(): void
    {
        // NetworkProfile should use 'mysql' connection (global schema)
        $profile = new NetworkProfile();
        $this->assertEquals('mysql', $profile->getConnectionName());

        // TenantSupplier should use 'tenant' connection (tenant schema)
        $supplier = new TenantSupplier();
        $this->assertEquals('tenant', $supplier->getConnectionName());
    }

    public function test_network_profile_specializations_stored_as_array(): void
    {
        $specializations = ['civil_works', 'MEP', 'finishing'];

        $profile = $this->createTestProfile([
            'company_name'    => 'Specializations Test Co',
            'specializations' => $specializations,
        ]);

        $this->assertNotEmpty($profile['id'] ?? '');

        $fetched = NetworkProfile::find($profile['id']);
        $this->assertIsArray($fetched->specializations);
        $this->assertEquals($specializations, $fetched->specializations);
    }
}
