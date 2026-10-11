<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Audit\AuditEntry;
use App\Network\NetworkProfile;
use App\Network\NetworkTender;
use App\Network\Events\TenderPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NetworkTenderTest — Feature tests for NETWORK-002.
 *
 * Tests cover:
 *  - Tender CRUD (create, list, show, update)
 *  - Lifecycle transitions (draft → published → closed → awarded)
 *  - Invalid lifecycle transitions rejected
 *  - Visibility model (public/private/invited/matched)
 *  - Authorization (unauthorized access rejected)
 *  - Audit logging
 *  - TenderPublished event dispatched on publish
 *  - Cross-tenant isolation
 *  - NETWORK-001 regression (NetworkProfile tests unaffected)
 *
 * Uses bootstrap_iam_tenant.php pattern for tenant setup.
 */
class NetworkTenderTest extends TestCase
{
    private array  $tenantCtx   = [];
    private string $token       = '';
    private string $tenantSlug  = '';
    private string $password    = 'Network002Test!';
    private array  $publisherProfile = [];

    // -------------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';

        $this->tenantCtx  = bootstrapAuthSmokeTenant($this->password);
        $this->tenantSlug = $this->tenantCtx['tenant']->slug;

        // Run Network migrations on global schema
        $this->runNetworkMigrations();

        // Obtain PAT token
        $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->postJson('/api/auth/login', [
                'email'    => $this->tenantCtx['user']->email,
                'password' => $this->password,
            ]);

        $this->token = $loginResponse->json('token') ?? '';

        // Create a publisher NetworkProfile directly in DB (avoids auth state side-effects)
        $profileId = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('mysql')->table('network_profiles')->insert([
            'id'                     => $profileId,
            'company_name'           => 'Test Publisher Co.',
            'profile_type'           => 'owner',
            'status'                 => 'active',
            'region'                 => 'Qatar',
            'managed_by_tenant_slug' => 'auth-smoke',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
        $this->publisherProfile = ['id' => $profileId, 'company_name' => 'Test Publisher Co.'];
    }

    protected function tearDown(): void
    {
        // Clean up network_tenders created during this test
        try {
            DB::connection('mysql')->table('network_tenders')
                ->where('managed_by_tenant_slug', 'auth-smoke')
                ->delete();
        } catch (\Throwable) {}

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
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $migrator->setConnection('mysql');

        if (! Schema::connection('mysql')->hasTable('network_profiles')) {
            $migrator->run(database_path('migrations/network'));
        } elseif (! Schema::connection('mysql')->hasTable('network_tenders')) {
            $migrator->run(database_path('migrations/network'));
        }
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function createTestTender(array $overrides = []): array
    {
        $data = array_merge([
            'publisher_profile_id' => $this->publisherProfile['id'] ?? '',
            'title'                => 'Test Tender — Construction Works',
            'category'             => 'civil',
            'region'               => 'Qatar',
            'visibility'           => 'public',
        ], $overrides);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/tenders', $data);

        return $response->json('data') ?? [];
    }

    // -------------------------------------------------------------------------
    // Tests — Create
    // -------------------------------------------------------------------------

    public function test_create_tender_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/tenders', [
                'publisher_profile_id' => $this->publisherProfile['id'],
                'title'                => 'Road Construction Project',
                'category'             => 'civil',
                'region'               => 'Qatar',
                'visibility'           => 'public',
                'deadline'             => '2027-03-01T00:00:00Z',
                'budget_min'           => 500000,
                'budget_max'           => 2000000,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Road Construction Project');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.visibility', 'public');
        $response->assertJsonPath('data.category', 'civil');
        $this->assertNotEmpty($response->json('data.id'));
    }

    public function test_create_tender_validates_required_fields(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/tenders', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['publisher_profile_id', 'title', 'category']]);
    }

    public function test_create_tender_validates_visibility_enum(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/tenders', [
                'publisher_profile_id' => $this->publisherProfile['id'],
                'title'                => 'Test',
                'category'             => 'civil',
                'visibility'           => 'invalid_visibility',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['visibility']]);
    }

    public function test_create_tender_with_invalid_publisher_returns_422(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/network/tenders', [
                'publisher_profile_id' => '00000000-0000-0000-0000-000000000000',
                'title'                => 'Test Tender',
                'category'             => 'civil',
            ]);

        $response->assertStatus(422);
    }

    public function test_create_all_valid_visibility_types(): void
    {
        foreach (['public', 'private', 'invited', 'matched'] as $visibility) {
            $response = $this->withHeaders($this->authHeaders())
                ->postJson('/api/v1/network/tenders', [
                    'publisher_profile_id' => $this->publisherProfile['id'],
                    'title'                => "Tender - {$visibility}",
                    'category'             => 'civil',
                    'visibility'           => $visibility,
                ]);

            $response->assertStatus(201, "Failed for visibility: {$visibility}");
            $response->assertJsonPath('data.visibility', $visibility);
        }
    }

    // -------------------------------------------------------------------------
    // Tests — Read
    // -------------------------------------------------------------------------

    public function test_get_tender_returns_200(): void
    {
        $tender = $this->createTestTender();

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders/' . $tender['id']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $tender['id']);
        $response->assertJsonPath('data.title', $tender['title']);
    }

    public function test_get_nonexistent_tender_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    public function test_list_tenders_returns_paginated(): void
    {
        $this->createTestTender(['title' => 'Tender A']);
        $this->createTestTender(['title' => 'Tender B']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders');

        $response->assertStatus(200);
        $this->assertArrayHasKey('data', $response->json());
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_list_tenders_filters_by_category(): void
    {
        $this->createTestTender(['category' => 'civil', 'title' => 'Civil Tender']);
        $this->createTestTender(['category' => 'electrical', 'title' => 'Electrical Tender']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders?category=civil');

        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertEquals('civil', $item['category']);
        }
    }

    // -------------------------------------------------------------------------
    // Tests — Update
    // -------------------------------------------------------------------------

    public function test_update_tender_returns_200(): void
    {
        $tender = $this->createTestTender();

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'], [
                'title'  => 'Updated Tender Title',
                'region' => 'Doha',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Updated Tender Title');
        $response->assertJsonPath('data.region', 'Doha');
    }

    public function test_update_nonexistent_tender_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/00000000-0000-0000-0000-000000000000', [
                'title' => 'Updated',
            ]);

        $response->assertStatus(404);
    }

    public function test_update_published_tender_returns_422(): void
    {
        $tender = $this->createTestTender();

        // Publish first
        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        // Try to update — should fail
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'], [
                'title' => 'Should Fail',
            ]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Tests — Lifecycle
    // -------------------------------------------------------------------------

    public function test_publish_tender_transitions_status(): void
    {
        $tender = $this->createTestTender();
        $this->assertEquals('draft', $tender['status']);

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'published');
    }

    public function test_close_tender_transitions_status(): void
    {
        $tender = $this->createTestTender();

        // draft → published
        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        // published → closed
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/close');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'closed');
    }

    public function test_award_tender_transitions_status(): void
    {
        $tender = $this->createTestTender();

        // draft → published → closed → awarded
        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');
        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/close');

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/award');

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'awarded');
    }

    public function test_invalid_lifecycle_transition_rejected(): void
    {
        $tender = $this->createTestTender();

        // Cannot close a draft tender (must publish first)
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/close');

        $response->assertStatus(422);
    }

    public function test_cannot_publish_already_published_tender(): void
    {
        $tender = $this->createTestTender();

        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        // Try to publish again
        $response = $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Tests — Visibility
    // -------------------------------------------------------------------------

    public function test_public_tender_accessible_to_authenticated_user(): void
    {
        $tender = $this->createTestTender(['visibility' => 'public']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders/' . $tender['id']);

        $response->assertStatus(200);
    }

    public function test_private_tender_accessible_to_managing_tenant(): void
    {
        $tender = $this->createTestTender(['visibility' => 'private']);

        // Managing tenant can access
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders/' . $tender['id']);

        $response->assertStatus(200);
    }

    public function test_private_tender_not_in_public_list(): void
    {
        // Create a private tender managed by a different slug
        DB::connection('mysql')->table('network_tenders')->insert([
            'id'                     => \Illuminate\Support\Str::uuid()->toString(),
            'publisher_profile_id'   => $this->publisherProfile['id'],
            'title'                  => 'Private Tender Other Tenant',
            'category'               => 'civil',
            'status'                 => 'draft',
            'visibility'             => 'private',
            'managed_by_tenant_slug' => 'other-tenant-slug',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        // Current tenant should NOT see the other tenant's private tender
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders?visibility=private');

        $response->assertStatus(200);
        foreach ($response->json('data') as $item) {
            $this->assertNotEquals('other-tenant-slug', $item['managed_by_tenant_slug'] ?? '');
        }
    }

    // -------------------------------------------------------------------------
    // Tests — Authorization
    // -------------------------------------------------------------------------

    public function test_unauthorized_access_rejected(): void
    {
        $response = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->postJson('/api/v1/network/tenders', [
                'publisher_profile_id' => $this->publisherProfile['id'],
                'title'                => 'Unauthorized',
                'category'             => 'civil',
            ]);

        $response->assertStatus(401);
    }

    public function test_list_without_auth_returns_401(): void
    {
        $response = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->getJson('/api/v1/network/tenders');

        $response->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Tests — Audit
    // -------------------------------------------------------------------------

    public function test_tender_audit_logged(): void
    {
        $tender = $this->createTestTender();

        $auditEntry = AuditEntry::where('event', 'network.tender.created')
            ->where('subject_id', $tender['id'])
            ->first();

        $this->assertNotNull($auditEntry, 'Audit entry for network.tender.created not found.');
    }

    public function test_publish_audit_logged(): void
    {
        $tender = $this->createTestTender();

        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        $auditEntry = AuditEntry::where('event', 'network.tender.published')
            ->where('subject_id', $tender['id'])
            ->first();

        $this->assertNotNull($auditEntry, 'Audit entry for network.tender.published not found.');
    }

    // -------------------------------------------------------------------------
    // Tests — TenderPublished Event
    // -------------------------------------------------------------------------

    public function test_tender_published_event_dispatched_on_publish(): void
    {
        Event::fake([TenderPublished::class]);

        $tender = $this->createTestTender();

        $this->withHeaders($this->authHeaders())
            ->patchJson('/api/v1/network/tenders/' . $tender['id'] . '/publish');

        Event::assertDispatched(TenderPublished::class, function (TenderPublished $event) use ($tender) {
            return $event->tenderListingId === $tender['id'];
        });
    }

    public function test_tender_published_event_not_dispatched_on_create(): void
    {
        Event::fake([TenderPublished::class]);

        $this->createTestTender();

        Event::assertNotDispatched(TenderPublished::class);
    }

    // -------------------------------------------------------------------------
    // Tests — Cross-tenant isolation
    // -------------------------------------------------------------------------

    public function test_no_direct_cross_schema_coupling(): void
    {
        // Verify network_tenders table is in global (mysql) schema
        $this->assertTrue(
            Schema::connection('mysql')->hasTable('network_tenders'),
            'network_tenders table must exist in global (mysql) schema.'
        );

        // Verify network_tenders does NOT exist in tenant schema
        $this->assertFalse(
            Schema::connection('tenant')->hasTable('network_tenders'),
            'network_tenders must NOT exist in tenant schema.'
        );
    }

    public function test_tender_specializations_stored_as_array(): void
    {
        $tender = $this->createTestTender([
            'specializations' => ['concrete', 'steel', 'foundations'],
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/network/tenders/' . $tender['id']);

        $response->assertStatus(200);
        $specializations = $response->json('data.specializations');
        $this->assertIsArray($specializations);
        $this->assertContains('concrete', $specializations);
    }
}
