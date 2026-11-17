<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformTenantPrivacyTest extends TestCase
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

    public function test_platform_tenant_responses_mask_cr_qid_and_preserve_verification_and_search(): void
    {
        $operator = $this->createPlatformOperator();
        $headers = ['Authorization' => 'Bearer '.$operator->createPlatformToken('privacy-test')->plainTextToken];
        $tenant = new Tenant();
        $tenant->slug = 'privacy-test';
        $tenant->name = 'Privacy Test';
        $tenant->schema_name = 'tenant_privacy_test';
        $tenant->status = 'active';
        $tenant->legal_type = 'organization';
        $tenant->activity_type = 'contractor';
        $tenant->responsible_qid = '28100123456';
        $tenant->save();

        $list = $this->getJson('/api/platform/tenants?search=privacy-test', $headers)
            ->assertOk();
        $this->assertStringNotContainsString('28100123456', $list->getContent());

        $created = $this->postJson("/api/platform/tenants/{$tenant->id}/identifiers", [
            'type' => 'cr',
            'number' => 'CR-123456789',
        ], $headers)->assertCreated();
        $identifierId = $created->json('identifier.id');
        $this->assertSame('**********89', $created->json('identifier.number'));
        $this->assertStringNotContainsString('CR-123456789', $created->getContent());

        $updated = $this->patchJson("/api/platform/tenants/{$tenant->id}/identifiers/{$identifierId}", [
            'type' => 'cr',
            'number' => 'CR-987654321',
        ], $headers)->assertOk();
        $this->assertSame('**********21', $updated->json('identifier.number'));
        $this->assertStringNotContainsString('CR-987654321', $updated->getContent());

        $tenant->identifiers()->create([
            'type' => 'professional_license',
            'number' => 'PRO-123456',
            'validity_status' => 'unverified',
        ]);
        $identifierList = $this->getJson('/api/platform/tenants?search=CR-987654321', $headers)
            ->assertOk();
        $this->assertSame($tenant->id, $identifierList->json('data.0.id'));
        $this->assertSame('**********21', $identifierList->json('data.0.identifiers.0.number'));
        $this->assertSame('professional_license', $identifierList->json('data.0.identifiers.1.type'));
        $this->assertSame('********56', $identifierList->json('data.0.identifiers.1.number'));
        $this->assertStringNotContainsString('CR-987654321', $identifierList->getContent());
        $this->assertStringNotContainsString('PRO-123456', $identifierList->getContent());

        $detail = $this->getJson("/api/platform/tenants/{$tenant->id}", $headers)->assertOk();
        $this->assertSame('*********56', $detail->json('tenant.responsible_qid'));
        $this->assertSame('**********21', $detail->json('tenant.identifiers.0.number'));
        $this->assertStringNotContainsString('28100123456', $detail->getContent());
        $this->assertStringNotContainsString('CR-987654321', $detail->getContent());

        $tenantUpdate = $this->patchJson("/api/platform/tenants/{$tenant->id}", [
            'responsible_qid' => '29999888888',
        ], $headers)->assertOk();
        $this->assertSame('*********88', $tenantUpdate->json('tenant.responsible_qid'));
        $this->assertSame('**********21', $tenantUpdate->json('tenant.identifiers.0.number'));
        $this->assertStringNotContainsString('29999888888', $tenantUpdate->getContent());

        $legacyQidSearch = $this->getJson('/api/platform/tenants?search=999888', $headers)
            ->assertOk();
        $this->assertSame($tenant->id, $legacyQidSearch->json('data.0.id'));
        $this->assertSame('*********88', $legacyQidSearch->json('data.0.responsible_qid'));
        $this->assertStringNotContainsString('29999888888', $legacyQidSearch->getContent());

        $approved = $this->postJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifierId}/approve",
            [
                'review_confirmed' => true,
                'verification_reference' => 'registry-check-2026',
            ],
            $headers,
        )->assertOk();
        $this->assertSame('valid', $approved->json('identifier.validity_status'));
        $this->assertSame('**********21', $approved->json('identifier.number'));
        $this->assertStringNotContainsString('CR-987654321', $approved->getContent());

        $identifier = TenantIdentifier::on('mysql')->findOrFail($identifierId);
        $this->assertSame('CR-987654321', $identifier->number);
        $this->assertSame('valid', $identifier->validity_status);
        $this->assertTrue(TenantIdentifier::hasValidLoginIdentifierForTenant($tenant->fresh()));

        $search = $this->getJson('/api/platform/tenants?search=CR-987654321', $headers)
            ->assertOk();
        $this->assertSame($tenant->id, $search->json('data.0.id'));
        $this->assertStringNotContainsString('CR-987654321', $search->getContent());

        $resolution = $this->postJson('/api/auth/tenant/resolve', [
            'identifier_type' => 'cr',
            'identifier' => 'CR-987654321',
        ])->assertOk();
        $this->assertArrayHasKey('resolution_token', $resolution->json());
        $this->assertStringNotContainsString('CR-987654321', $resolution->getContent());

        $this->postJson('/api/platform/tenants', [
            'name' => 'Created Privacy Tenant',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_qid' => '27777123456',
            'identifiers' => [['type' => 'cr', 'number' => 'CR-555555555']],
        ], $headers)->assertMethodNotAllowed();
    }

    public function test_full_identifier_permission_discloses_and_audits_values_while_tenant_view_alone_masks_them(): void
    {
        $operator = $this->createPlatformOperator();
        $tenant = new Tenant();
        $tenant->slug = 'full-identifier-access';
        $tenant->name = 'Full Identifier Access';
        $tenant->schema_name = 'tenant_full_identifier_access';
        $tenant->status = 'active';
        $tenant->legal_type = 'organization';
        $tenant->responsible_qid = '28100123456';
        $tenant->save();
        $identifier = $tenant->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-123456789',
            'validity_status' => 'valid',
        ]);
        $headers = ['Authorization' => 'Bearer '.$operator->createPlatformToken('identifier-access')->plainTextToken];

        $maskedList = $this->getJson('/api/platform/tenants?search=full-identifier-access', $headers)
            ->assertOk();
        $this->assertSame('*********56', $maskedList->json('data.0.responsible_qid'));
        $this->assertSame('**********89', $maskedList->json('data.0.identifiers.0.number'));

        $this->grantFullIdentifierViewPermission($operator);

        $fullList = $this->getJson('/api/platform/tenants?search=full-identifier-access', $headers)
            ->assertOk();
        $this->assertSame('28100123456', $fullList->json('data.0.responsible_qid'));
        $this->assertSame('CR-123456789', $fullList->json('data.0.identifiers.0.number'));
        $fullDetail = $this->getJson("/api/platform/tenants/{$tenant->id}", $headers)->assertOk();
        $this->assertSame('28100123456', $fullDetail->json('tenant.responsible_qid'));
        $this->assertSame('CR-123456789', $fullDetail->json('tenant.identifiers.0.number'));

        $auditEntries = DB::connection('mysql')->table('platform_audit_entries')
            ->where('actor_id', $operator->getKey())
            ->where('event', 'platform.tenant.identifiers.viewed')
            ->where('subject_id', (string) $tenant->id)
            ->get();
        $this->assertCount(2, $auditEntries);
        foreach ($auditEntries as $entry) {
            $this->assertStringNotContainsString('28100123456', (string) $entry->after);
            $this->assertStringNotContainsString('CR-123456789', (string) $entry->after);
        }

        $this->assertSame($identifier->getKey(), $fullList->json('data.0.identifiers.0.id'));
    }

    public function test_full_identifier_disclosure_fails_when_audit_storage_is_unavailable(): void
    {
        $operator = $this->createPlatformOperator();
        $this->grantFullIdentifierViewPermission($operator);
        $tenant = new Tenant();
        $tenant->slug = 'audit-unavailable';
        $tenant->name = 'Audit Unavailable';
        $tenant->schema_name = 'tenant_audit_unavailable';
        $tenant->status = 'active';
        $tenant->legal_type = 'organization';
        $tenant->save();
        $tenant->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-123456789',
            'validity_status' => 'valid',
        ]);
        $headers = ['Authorization' => 'Bearer '.$operator->createPlatformToken('audit-unavailable')->plainTextToken];

        Schema::connection('mysql')->drop('platform_audit_entries');

        $this->getJson('/api/platform/tenants?search=audit-unavailable', $headers)
            ->assertStatus(503)
            ->assertJsonPath('message', 'Sensitive identifier disclosure is unavailable because audit logging is not configured.');
    }

    public function test_identifier_search_and_classification_are_applied_before_pagination(): void
    {
        $operator = $this->createPlatformOperator();
        $headers = ['Authorization' => 'Bearer '.$operator->createPlatformToken('pagination-test')->plainTextToken];
        $tenantIds = [];

        foreach (['101', '202'] as $suffix) {
            $tenant = new Tenant();
            $tenant->slug = 'filtered-company-'.$suffix;
            $tenant->name = 'Filtered Company '.$suffix;
            $tenant->schema_name = 'tenant_filtered_company_'.$suffix;
            $tenant->status = 'active';
            $tenant->legal_type = 'organization';
            $tenant->activity_type = 'contractor';
            $tenant->save();
            $tenant->identifiers()->create([
                'type' => 'cr',
                'number' => 'CR-PAGE-'.$suffix,
                'validity_status' => 'unverified',
            ]);
            $tenantIds[] = $tenant->id;
        }

        $baseQuery = '/api/platform/tenants?classification=contracting_company&search=CR-PAGE&per_page=1';
        $firstPage = $this->getJson($baseQuery.'&page=1', $headers)->assertOk();
        $secondPage = $this->getJson($baseQuery.'&page=2', $headers)->assertOk();
        $pagedIds = [
            $firstPage->json('data.0.id'),
            $secondPage->json('data.0.id'),
        ];

        $this->assertSame(2, $firstPage->json('total'));
        $this->assertSame(2, $secondPage->json('total'));
        $this->assertSame(2, $firstPage->json('last_page'));
        $this->assertSame(2, $secondPage->json('last_page'));
        $this->assertEqualsCanonicalizing($tenantIds, $pagedIds);
        $this->assertSame('contracting_company', $firstPage->json('data.0.classification'));
        $this->assertSame('contracting_company', $secondPage->json('data.0.classification'));
    }

    private function createPlatformOperator(): PlatformUser
    {
        $operator = new PlatformUser();
        $operator->name = 'Privacy Test Operator';
        $operator->email = 'privacy-operator@example.test';
        $operator->password = Hash::make('test-password');
        $operator->is_active = true;
        $operator->save();

        $roleId = DB::connection('mysql')->table('platform_roles')->insertGetId([
            'name' => 'Privacy Reviewer',
            'slug' => 'privacy-reviewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'platform.tenants.view',
            'platform.tenants.create',
            'platform.tenants.update',
            'platform.tenants.identifiers.approve',
        ] as $permission) {
            $permissionId = DB::connection('mysql')->table('platform_permissions')->insertGetId([
                'name' => $permission,
                'description' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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

    private function grantFullIdentifierViewPermission(PlatformUser $operator): void
    {
        $roleId = DB::connection('mysql')->table('platform_user_roles')
            ->where('platform_user_id', $operator->getKey())
            ->value('platform_role_id');
        $permissionId = DB::connection('mysql')->table('platform_permissions')->insertGetId([
            'name' => 'platform.tenants.identifiers.view',
            'description' => 'View complete tenant identifiers',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('mysql')->table('platform_role_permissions')->insert([
            'platform_role_id' => $roleId,
            'platform_permission_id' => $permissionId,
        ]);
    }

    private function createSchema(): void
    {
        Schema::connection('mysql')->create('platform_users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_personal_access_tokens', function ($table): void {
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
        Schema::connection('mysql')->create('platform_roles', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_permissions', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('platform_role_permissions', function ($table): void {
            $table->unsignedBigInteger('platform_role_id');
            $table->unsignedBigInteger('platform_permission_id');
            $table->primary(['platform_role_id', 'platform_permission_id']);
        });
        Schema::connection('mysql')->create('platform_user_roles', function ($table): void {
            $table->unsignedBigInteger('platform_user_id');
            $table->unsignedBigInteger('platform_role_id');
            $table->primary(['platform_user_id', 'platform_role_id']);
        });
        Schema::connection('mysql')->create('platform_audit_entries', function ($table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::connection('mysql')->create('tenants', function ($table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('schema_name')->unique();
            $table->string('status');
            $table->string('legal_type')->nullable();
            $table->string('activity_type')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('responsible_name')->nullable();
            $table->string('responsible_qid')->nullable();
            $table->date('qid_expires_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('tenant_identifiers', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('type', 64);
            $table->string('number', 128);
            $table->string('normalized_number', 128);
            $table->date('expires_at')->nullable();
            $table->string('validity_status', 32)->default('unverified');
            $table->string('document_path')->nullable();
            $table->timestamps();
            $table->unique(['type', 'normalized_number']);
        });
        Schema::connection('mysql')->create('tenant_settings', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
