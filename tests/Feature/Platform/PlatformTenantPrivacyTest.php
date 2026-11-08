<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\Foundation\TenantProvisioner;
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

        $provisioner = \Mockery::mock(TenantProvisioner::class);
        $provisioner->shouldReceive('provision')
            ->once()
            ->andReturnUsing(function (string $slug, string $name): Tenant {
                $createdTenant = new Tenant();
                $createdTenant->slug = $slug;
                $createdTenant->name = $name;
                $createdTenant->schema_name = 'tenant_'.$slug;
                $createdTenant->status = 'pending';
                $createdTenant->save();

                return $createdTenant;
            });
        $this->app->instance(TenantProvisioner::class, $provisioner);

        $tenantCreated = $this->postJson('/api/platform/tenants', [
            'name' => 'Created Privacy Tenant',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'responsible_qid' => '27777123456',
            'identifiers' => [
                ['type' => 'cr', 'number' => 'CR-555555555'],
            ],
        ], $headers)->assertCreated();
        $this->assertSame('*********56', $tenantCreated->json('tenant.responsible_qid'));
        $this->assertSame('**********55', $tenantCreated->json('tenant.identifiers.0.number'));
        $this->assertStringNotContainsString('27777123456', $tenantCreated->getContent());
        $this->assertStringNotContainsString('CR-555555555', $tenantCreated->getContent());
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
