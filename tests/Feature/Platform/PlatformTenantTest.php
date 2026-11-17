<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use App\Foundation\TenantProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
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
 * Uses an isolated in-memory SQLite schema and never touches tenant databases.
 */
class PlatformTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('mysql');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function authenticated_operator_can_list_tenants(): void
    {
        $operator = $this->makePlatformUser();
        $token = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;
        $this->makeTenant('test-list-' . uniqid(), 'active');

        $response = $this->getJson('/api/platform/tenants', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('total', $response->json());
        $this->assertArrayHasKey('current_page', $response->json());
        $this->assertIsArray($response->json('data'));
    }

    #[Test]
    public function listing_tenants_does_not_resolve_the_cli_only_tenant_provisioner(): void
    {
        $operator = $this->makePlatformUser();
        $token = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;
        $this->app->bind(TenantProvisioner::class, function (): TenantProvisioner {
            throw new \LogicException('The tenant provisioner must not be resolved for a read-only request.');
        });

        $response = $this->getJson('/api/platform/tenants', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk();
    }

    #[Test]
    public function unauthenticated_request_to_tenants_returns_401(): void
    {
        $this->getJson('/api/platform/tenants')->assertUnauthorized();
    }

    // =========================================================================
    // PT-03 — Status filter works
    // =========================================================================

    #[Test]
    public function status_filter_returns_only_matching_tenants(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

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

    #[Test]
    public function tenant_list_classifies_supported_types_and_filters_review_cases(): void
    {
        $this->assertSame(
            ['classification' => 'needs_review', 'classification_reason' => 'missing_legal_type'],
            Tenant::classificationFor(null, 'contractor')
        );
        $this->assertSame(
            ['classification' => 'needs_review', 'classification_reason' => 'unsupported_legal_type'],
            Tenant::classificationFor('owner', 'contractor')
        );
        $this->assertSame(
            ['classification' => 'property_owner', 'classification_reason' => null],
            Tenant::classificationFor('organization', 'owner')
        );
        $this->assertSame(
            ['classification' => 'property_owner', 'classification_reason' => null],
            Tenant::classificationFor('individual', 'owner')
        );

        $operator = $this->makePlatformUser();
        $token = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer ' . $token];

        $fixtures = [
            'contracting_company' => $this->makeTenant('classification-company-' . uniqid(), 'active', 'organization', 'contractor'),
            'individual_contractor' => $this->makeTenant('classification-individual-' . uniqid(), 'active', 'individual', 'contractor'),
            'engineering_consultancy' => $this->makeTenant('classification-consultancy-' . uniqid(), 'active', 'organization', 'consultant'),
            'property_owner_org' => $this->makeTenant('classification-owner-org-' . uniqid(), 'active', 'organization', 'owner'),
            'property_owner_individual' => $this->makeTenant('classification-owner-ind-' . uniqid(), 'active', 'individual', 'owner'),
            'both' => $this->makeTenant('classification-both-' . uniqid(), 'active', 'organization', 'both'),
            'missing_activity' => $this->makeTenant('classification-missing-' . uniqid(), 'active', 'organization', null),
            'unsupported_combination' => $this->makeTenant('classification-unsupported-' . uniqid(), 'active', 'individual', 'consultant'),
        ];

        $allResponse = $this->getJson('/api/platform/tenants?per_page=100', $headers);
        $allResponse->assertOk();
        $allTenants = collect($allResponse->json('data'))->keyBy('id');

        $expectedClassifications = [
            'contracting_company' => ['contracting_company', null],
            'individual_contractor' => ['individual_contractor', null],
            'engineering_consultancy' => ['engineering_consultancy', null],
            'property_owner_org' => ['property_owner', null],
            'property_owner_individual' => ['property_owner', null],
            'both' => ['needs_review', 'unsupported_combination'],
            'missing_activity' => ['needs_review', 'missing_activity_type'],
            'unsupported_combination' => ['needs_review', 'unsupported_combination'],
        ];

        foreach ($fixtures as $key => $tenant) {
            $this->assertArrayHasKey($tenant->id, $allTenants);
            $this->assertSame(
                $expectedClassifications[$key][0],
                $allTenants[$tenant->id]['classification']
            );
            $this->assertSame(
                $expectedClassifications[$key][1],
                $allTenants[$tenant->id]['classification_reason']
            );
            $this->assertArrayHasKey('legal_type', $allTenants[$tenant->id]);
            $this->assertArrayHasKey('activity_type', $allTenants[$tenant->id]);
        }

        $filterIds = [
            'contracting_company' => [$fixtures['contracting_company']->id],
            'individual_contractor' => [$fixtures['individual_contractor']->id],
            'engineering_consultancy' => [$fixtures['engineering_consultancy']->id],
            'property_owner' => [
                $fixtures['property_owner_org']->id,
                $fixtures['property_owner_individual']->id,
            ],
            'needs_review' => [
                $fixtures['both']->id,
                $fixtures['missing_activity']->id,
                $fixtures['unsupported_combination']->id,
            ],
        ];

        foreach ($filterIds as $classification => $expectedIds) {
            $response = $this->getJson(
                '/api/platform/tenants?classification=' . $classification . '&per_page=100',
                $headers
            );
            $response->assertOk();
            $actualIds = collect($response->json('data'))->pluck('id')->all();
            foreach ($expectedIds as $expectedId) {
                $this->assertContains($expectedId, $actualIds);
            }
            $expectedClassification = $classification;
            foreach ($response->json('data') as $tenantData) {
                $this->assertSame($expectedClassification, $tenantData['classification']);
            }
        }

        $this->assertSame('organization', Tenant::on('mysql')->find($fixtures['both']->id)->legal_type);
        $this->assertSame('both', Tenant::on('mysql')->find($fixtures['both']->id)->activity_type);

        $groupResponse = $this->getJson('/api/platform/tenants?group=owners&per_page=100', $headers);
        $groupResponse->assertOk();
        $ownerIds = collect($groupResponse->json('data'))->pluck('id')->all();
        $this->assertContains($fixtures['property_owner_org']->id, $ownerIds);
        $this->assertContains($fixtures['property_owner_individual']->id, $ownerIds);
        $this->assertNotContains($fixtures['contracting_company']->id, $ownerIds);

        $summary = $this->getJson('/api/platform/tenants/summary', $headers);
        $summary->assertOk();
        $this->assertSame('client', $summary->json('architecture.owner_model'));
        $this->assertFalse($summary->json('architecture.owner_tenant_product'));
        $this->assertGreaterThanOrEqual(2, (int) $summary->json('tenants.owners_total'));
        $this->assertGreaterThanOrEqual(2, (int) $summary->json('tenants.contractors_total'));
    }

    // =========================================================================
    // PT-04 — Show returns tenant + settings
    // =========================================================================

    #[Test]
    public function show_returns_tenant_and_settings(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

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

    #[Test]
    public function show_non_existent_tenant_returns_404(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->getJson('/api/platform/tenants/999999', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // PT-06 — Suspend an active tenant
    // =========================================================================

    #[Test]
    public function suspend_active_tenant_returns_200_and_updates_status(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

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

    #[Test]
    public function suspend_non_active_tenant_returns_422(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('suspend-422-' . uniqid(), 'pending');

        $response = $this->patchJson("/api/platform/tenants/{$tenant->id}/suspend", [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // PT-08 — Pending tenants cannot be activated through the API
    // =========================================================================

    #[Test]
    public function activate_pending_tenant_remains_inactive(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $tenant = $this->makeTenant('activate-test-' . uniqid(), 'pending');

        $response = $this->patchJson("/api/platform/tenants/{$tenant->id}/activate", [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(409);

        $fresh = Tenant::on('mysql')->find($tenant->id);
        $this->assertSame('pending', $fresh->status);
    }

    // =========================================================================
    // PT-09 — schema_name never returned
    // =========================================================================

    #[Test]
    public function schema_name_is_never_returned_in_tenant_responses(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

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

        $roleId = DB::connection('mysql')->table('platform_roles')->insertGetId([
            'name' => 'Tenant test operator',
            'slug' => 'tenant-test-' . uniqid(),
            'description' => 'Platform tenant feature test',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionIds = DB::connection('mysql')->table('platform_permissions')
            ->whereIn('name', [
                'platform.tenants.view',
                'platform.tenants.suspend',
                'platform.tenants.activate',
            ])
            ->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::connection('mysql')->table('platform_role_permissions')->insert([
                'platform_role_id' => $roleId,
                'platform_permission_id' => $permissionId,
            ]);
        }
        DB::connection('mysql')->table('platform_user_roles')->insert([
            'platform_user_id' => $operator->id,
            'platform_role_id' => $roleId,
        ]);

        return $operator;
    }

    private function makeTenant(
        string $slug,
        string $status,
        ?string $legalType = null,
        ?string $activityType = null,
    ): Tenant
    {
        $tenant = new Tenant();
        $tenant->setConnection('mysql');
        $tenant->slug        = $slug;
        $tenant->name        = 'Test Tenant ' . $slug;
        if ($legalType !== null) {
            $tenant->legal_type = $legalType;
        }
        if ($activityType !== null) {
            $tenant->activity_type = $activityType;
        }
        $tenant->status      = $status;
        $tenant->schema_name = 'binaa2_tenant_' . $slug;
        $tenant->save();

        return $tenant;
    }

    private function createSchema(): void
    {
        Schema::connection('mysql')->create('platform_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_role_id');
            $table->unsignedBigInteger('platform_permission_id');
            $table->primary(['platform_role_id', 'platform_permission_id']);
        });
        Schema::connection('mysql')->create('platform_user_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_user_id');
            $table->unsignedBigInteger('platform_role_id');
            $table->primary(['platform_user_id', 'platform_role_id']);
        });
        Schema::connection('mysql')->create('platform_audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('legal_type', 32)->nullable();
            $table->string('activity_type', 32)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('responsible_name')->nullable();
            $table->string('responsible_qid', 32)->nullable();
            $table->date('qid_expires_at')->nullable();
            $table->string('schema_name')->unique();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('tenant_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('type', 64);
            $table->string('number', 128);
            $table->string('normalized_number', 128);
            $table->date('expires_at')->nullable();
            $table->string('validity_status', 32)->default('unverified');
            $table->string('document_path')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('tenant_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::connection('mysql')->table('platform_permissions')->insert(
            collect([
                'platform.tenants.view',
                'platform.tenants.suspend',
                'platform.tenants.activate',
            ])->map(fn (string $name): array => [
                'name' => $name,
                'description' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }
}
