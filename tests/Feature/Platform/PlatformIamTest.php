<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Audit\PlatformAuditEntry;
use App\Foundation\PlatformPermission;
use App\Foundation\PlatformRole;
use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use App\Foundation\PlatformUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformIamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql.driver' => 'sqlite',
            'database.connections.mysql.database' => ':memory:',
            'database.connections.mysql.prefix' => '',
            'database.connections.mysql.foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');

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
        DB::connection('mysql')->table('platform_permissions')->insert(collect([
            'platform.users.view',
            'platform.users.create',
            'platform.users.update',
            'platform.users.assign-roles',
            'platform.roles.view',
            'platform.roles.create',
            'platform.roles.update',
            'platform.roles.delete',
            'platform.roles.assign-permissions',
            'platform.permissions.view',
            'platform.tenants.view',
            'platform.tenants.create',
            'platform.tenants.update',
            'platform.tenants.identifiers.approve',
            'platform.tenants.suspend',
            'platform.tenants.activate',
        ])->map(fn (string $name) => [
            'name' => $name,
            'description' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    #[Test]
    public function full_identifier_view_permission_is_defaulted_only_to_platform_owner(): void
    {
        $ownerRole = PlatformRole::on('mysql')->create([
            'name' => 'Platform Owner',
            'slug' => 'platform-owner',
            'is_system' => true,
        ]);
        $adminRole = PlatformRole::on('mysql')->create([
            'name' => 'Platform Admin',
            'slug' => 'platform-admin',
            'is_system' => true,
        ]);

        $migration = require database_path('migrations/shared/2026_11_08_000004_add_tenant_identifier_view_permission.php');
        $migration->up();

        $permission = PlatformPermission::on('mysql')
            ->where('name', 'platform.tenants.identifiers.view')
            ->firstOrFail();
        $this->assertTrue($ownerRole->permissions()->whereKey($permission->getKey())->exists());
        $this->assertFalse($adminRole->permissions()->whereKey($permission->getKey())->exists());
    }

    #[Test]
    public function only_platform_owner_can_grant_full_identifier_view_permission(): void
    {
        $migration = require database_path('migrations/shared/2026_11_08_000004_add_tenant_identifier_view_permission.php');
        $migration->up();
        $permissionId = PlatformPermission::on('mysql')
            ->where('name', 'platform.tenants.identifiers.view')
            ->value('id');
        $targetRole = $this->makeRole(['platform.users.view'], 'target');

        $admin = $this->makeOperator();
        $adminRole = $this->makeRole([
            'platform.roles.assign-permissions',
            'platform.permissions.view',
            'platform.roles.create',
            'platform.users.assign-roles',
            'platform.users.create',
            'platform.roles.view',
        ], 'platform-admin');
        $admin->platformRoles()->attach($adminRole->getKey());
        $this->postJson('/api/platform/roles', [
            'name' => 'Sensitive role attempt',
            'permission_ids' => [$permissionId],
        ], $this->headersFor($admin))->assertForbidden();
        $this->putJson('/api/platform/roles/'.$targetRole->getKey().'/permissions', [
            'permission_ids' => [$permissionId],
        ], $this->headersFor($admin))->assertForbidden();

        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());
        $this->assertSame('platform-owner', $owner->platformRoles()->firstOrFail()->slug);
        $this->app['auth']->forgetGuards();
        $ownerGrantResponse = $this->putJson('/api/platform/roles/'.$targetRole->getKey().'/permissions', [
            'permission_ids' => [$permissionId],
        ], $this->headersFor($owner));
        $this->assertSame(200, $ownerGrantResponse->status(), $ownerGrantResponse->getContent());

        $this->assertDatabaseHas('platform_role_permissions', [
            'platform_role_id' => $targetRole->getKey(),
            'platform_permission_id' => $permissionId,
        ], 'mysql');

        $targetUser = $this->makeOperator();
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/platform/users/'.$targetUser->getKey().'/roles', [
            'role_ids' => [$targetRole->getKey()],
        ], $this->headersFor($admin))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/platform/users/'.$targetUser->getKey().'/roles', [
            'role_ids' => [$targetRole->getKey()],
        ], $this->headersFor($owner))->assertOk();
    }

    #[Test]
    public function wildcard_pat_does_not_replace_platform_role_authorization(): void
    {
        $operator = $this->makeOperator();
        $response = $this->getJson('/api/platform/users', $this->headersFor($operator));

        $response->assertForbidden();
    }

    #[Test]
    public function platform_permissions_are_independent_and_audited(): void
    {
        $operator = $this->makeOperator();
        $role = $this->makeRole(['platform.users.view']);
        $operator->platformRoles()->attach($role->getKey());
        $headers = $this->headersFor($operator);

        $this->getJson('/api/platform/users', $headers)->assertOk();
        $this->getJson('/api/platform/roles', $headers)->assertForbidden();

        $auditCount = PlatformAuditEntry::on('mysql')->count();
        $this->putJson('/api/platform/users/'.$operator->getKey().'/roles', [
            'role_ids' => [],
        ], $headers)->assertForbidden();
        $this->assertSame($auditCount, PlatformAuditEntry::on('mysql')->count());
    }

    #[Test]
    public function platform_user_without_tenant_permission_cannot_manage_tenants(): void
    {
        $operator = $this->makeOperator();
        $headers = $this->headersFor($operator);

        $this->getJson('/api/platform/tenants', $headers)->assertForbidden();
        $this->postJson('/api/platform/tenants', [], $headers)->assertMethodNotAllowed();
        $this->patchJson('/api/platform/tenants/1/suspend', [], $headers)->assertForbidden();
        $this->patchJson('/api/platform/tenants/1/activate', [], $headers)->assertForbidden();
    }

    #[Test]
    public function tenant_permissions_are_independent_for_read_create_update_suspend_and_activate(): void
    {
        $operator = $this->makeOperator();
        $viewRole = $this->makeRole(['platform.tenants.view']);
        $operator->platformRoles()->attach($viewRole->getKey());
        $viewHeaders = $this->headersFor($operator);

        $this->getJson('/api/platform/tenants', $viewHeaders)->assertOk();
        $this->postJson('/api/platform/tenants', [], $viewHeaders)->assertMethodNotAllowed();
        $this->patchJson('/api/platform/tenants/1/suspend', [], $viewHeaders)->assertForbidden();
        $this->patchJson('/api/platform/tenants/1/activate', [], $viewHeaders)->assertForbidden();

        $operator->platformRoles()->sync([]);
        $suspendOnlyRole = $this->makeRole(['platform.tenants.suspend']);
        $operator->platformRoles()->attach($suspendOnlyRole->getKey());
        $suspendOnlyHeaders = $this->headersFor($operator);
        $this->patchJson('/api/platform/tenants/1/suspend', [], $suspendOnlyHeaders)->assertForbidden();

        $operator->platformRoles()->sync([]);
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->where('name', 'like', 'platform.tenants.%')->pluck('name')->all(),
        );
        $operator->platformRoles()->attach($ownerRole->getKey());
        $headers = $this->headersFor($operator);

        $this->postJson('/api/platform/tenants', [
            'name' => 'Test Platform Tenant',
            'legal_type' => 'organization',
            'activity_type' => 'contractor',
            'identifiers' => [['type' => 'cr', 'number' => 'CR-12345']],
        ], $headers)->assertMethodNotAllowed();

        $tenant = new Tenant();
        $tenant->setConnection('mysql');
        $tenant->slug = 'existing-platform-tenant';
        $tenant->name = 'Existing Platform Tenant';
        $tenant->schema_name = Tenant::deriveSchemaName($tenant->slug);
        $tenant->status = 'active';
        $tenant->save();
        $tenantId = $tenant->id;
        $this->patchJson('/api/platform/tenants/'.$tenantId, [
            'name' => 'Updated Platform Tenant',
        ], $headers)->assertOk()
            ->assertJsonPath('tenant.name', 'Updated Platform Tenant');
        $this->patchJson('/api/platform/tenants/'.$tenantId.'/suspend', [], $headers)
            ->assertOk()
            ->assertJsonPath('tenant.status', 'suspended');
        $this->patchJson('/api/platform/tenants/'.$tenantId.'/activate', [], $headers)
            ->assertOk()
            ->assertJsonPath('tenant.status', 'active');
    }

    #[Test]
    public function identifier_approval_requires_its_dedicated_permission(): void
    {
        $operator = $this->makeOperator();
        $role = $this->makeRole(['platform.tenants.view', 'platform.tenants.update']);
        $operator->platformRoles()->attach($role->getKey());
        $tenant = $this->makeIdentifierTestTenant('organization');
        $identifier = $tenant->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-'.uniqid(),
            'validity_status' => 'unverified',
        ]);
        $auditCount = PlatformAuditEntry::on('mysql')->count();

        $this->postJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifier->id}/approve",
            [
                'review_confirmed' => true,
                'verification_reference' => 'official-register:case-123',
            ],
            $this->headersFor($operator),
        )->assertForbidden();

        $this->assertSame('unverified', $identifier->fresh()->validity_status);
        $this->assertSame($auditCount, PlatformAuditEntry::on('mysql')->count());
    }

    #[Test]
    public function identifier_approval_records_actor_time_and_result(): void
    {
        $operator = $this->makeOperator();
        $role = $this->makeRole([
            'platform.tenants.view',
            'platform.tenants.identifiers.approve',
        ]);
        $operator->platformRoles()->attach($role->getKey());
        $tenant = $this->makeIdentifierTestTenant('organization');
        $identifier = $tenant->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-'.uniqid(),
            'validity_status' => 'unverified',
            'expires_at' => now()->addYear()->toDateString(),
        ]);

        $this->postJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifier->id}/approve",
            [
                'review_confirmed' => false,
                'verification_reference' => 'official-register:case-456',
            ],
            $this->headersFor($operator),
        )->assertUnprocessable();
        $this->assertSame('unverified', $identifier->fresh()->validity_status);
        $this->assertSame(0, PlatformAuditEntry::on('mysql')->count());

        $response = $this->postJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifier->id}/approve",
            [
                'review_confirmed' => true,
                'verification_reference' => 'official-register:case-456',
            ],
            $this->headersFor($operator),
        )->assertOk()
            ->assertJsonPath('identifier.validity_status', 'valid');

        $audit = PlatformAuditEntry::on('mysql')
            ->where('event', 'platform.tenant_identifier.approved')
            ->where('subject_id', (string) $identifier->id)
            ->firstOrFail();

        $this->assertSame($operator->getKey(), $audit->actor_id);
        $this->assertNotNull($audit->created_at);
        $this->assertSame('unverified', $audit->before['validity_status']);
        $this->assertSame('approved', $audit->after['result']);
        $this->assertSame('valid', $audit->after['validity_status']);
        $this->assertSame('official-register:case-456', $audit->after['verification_reference']);
        $this->assertSame('valid', $response->json('identifier.validity_status'));
    }

    #[Test]
    public function identifier_approval_rejects_entity_mismatch_expiration_duplicates_and_reapproval(): void
    {
        $operator = $this->makeOperator();
        $role = $this->makeRole([
            'platform.tenants.view',
            'platform.tenants.identifiers.approve',
        ]);
        $operator->platformRoles()->attach($role->getKey());
        $headers = $this->headersFor($operator);

        $individual = $this->makeIdentifierTestTenant('individual');
        $wrongType = $individual->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-'.uniqid(),
            'validity_status' => 'unverified',
        ]);
        $this->approveIdentifierRequest($individual, $wrongType, $headers)->assertUnprocessable();

        $company = $this->makeIdentifierTestTenant('organization');
        $expired = $company->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-'.uniqid(),
            'expires_at' => now()->subDay()->toDateString(),
            'validity_status' => 'unverified',
        ]);
        $this->approveIdentifierRequest($company, $expired, $headers)->assertUnprocessable();

        $duplicateValue = 'CR-DUPLICATE-'.uniqid();
        $existingTenant = $this->makeIdentifierTestTenant('organization');
        $existingTenant->identifiers()->create([
            'type' => 'cr',
            'number' => $duplicateValue,
            'validity_status' => 'valid',
        ]);
        $duplicateTenant = $this->makeIdentifierTestTenant('organization');
        $duplicate = $duplicateTenant->identifiers()->create([
            'type' => 'cr',
            'number' => $duplicateValue,
            'validity_status' => 'unverified',
        ]);
        $this->approveIdentifierRequest($duplicateTenant, $duplicate, $headers)->assertUnprocessable();

        $validTenant = $this->makeIdentifierTestTenant('organization');
        $valid = $validTenant->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-'.uniqid(),
            'validity_status' => 'valid',
        ]);
        $this->approveIdentifierRequest($validTenant, $valid, $headers)->assertUnprocessable();
    }

    #[Test]
    public function generic_identifier_update_cannot_approve_and_resets_previous_approval(): void
    {
        $operator = $this->makeOperator();
        $role = $this->makeRole(['platform.tenants.view', 'platform.tenants.update']);
        $operator->platformRoles()->attach($role->getKey());
        $tenant = $this->makeIdentifierTestTenant('organization');
        $identifier = $tenant->identifiers()->create([
            'type' => 'cr',
            'number' => 'CR-'.uniqid(),
            'validity_status' => 'valid',
        ]);

        $this->patchJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifier->id}",
            [
                'type' => 'cr',
                'number' => $identifier->number,
                'validity_status' => 'valid',
            ],
            $this->headersFor($operator),
        )->assertUnprocessable();
        $this->assertSame('valid', $identifier->fresh()->validity_status);

        $this->patchJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifier->id}",
            [
                'type' => 'cr',
                'number' => $identifier->number,
            ],
            $this->headersFor($operator),
        )->assertOk()
            ->assertJsonPath('identifier.validity_status', 'unverified');
    }

    private function approveIdentifierRequest(Tenant $tenant, TenantIdentifier $identifier, array $headers)
    {
        return $this->postJson(
            "/api/platform/tenants/{$tenant->id}/identifiers/{$identifier->id}/approve",
            [
                'review_confirmed' => true,
                'verification_reference' => 'official-register:test-case',
            ],
            $headers,
        );
    }

    private function makeIdentifierTestTenant(string $legalType): Tenant
    {
        $tenant = new Tenant();
        $tenant->setConnection('mysql');
        $tenant->slug = 'identifier-review-'.uniqid();
        $tenant->name = 'Identifier Review Tenant';
        $tenant->legal_type = $legalType;
        $tenant->schema_name = 'binaa2_tenant_identifier_review_'.uniqid();
        $tenant->status = 'active';
        $tenant->save();

        return $tenant;
    }

    #[Test]
    public function owner_can_create_a_role_and_assignment_is_written_to_platform_audit(): void
    {
        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());

        $response = $this->postJson('/api/platform/roles', [
            'name' => 'Support Operator',
            'description' => 'Can view platform users.',
            'permission_ids' => [
                PlatformPermission::on('mysql')->where('name', 'platform.users.view')->value('id'),
            ],
        ], $this->headersFor($owner));

        $response->assertCreated()
            ->assertJsonPath('role.slug', 'support-operator');

        $this->assertDatabaseHas('platform_audit_entries', [
            'actor_id' => $owner->getKey(),
            'event' => 'platform.role.created',
            'subject_type' => 'platform_role',
        ], 'mysql');
    }

    #[Test]
    public function platform_login_and_auth_me_remain_available_but_tenant_list_fails_closed_before_iam_migration(): void
    {
        $operator = $this->makeOperator();
        Schema::connection('mysql')->drop('platform_audit_entries');
        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $operator->email,
            'password' => 'platform-test-password',
        ])->assertOk();
        $this->assertNotEmpty($login->json('token'));

        Schema::connection('mysql')->drop('platform_user_roles');
        Schema::connection('mysql')->drop('platform_role_permissions');
        Schema::connection('mysql')->drop('platform_permissions');
        Schema::connection('mysql')->drop('platform_roles');

        $token = $operator->createPlatformToken('pre-migration-test', ['platform:*'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/platform/tenants?page=1&per_page=20', $headers)
            ->assertForbidden()
            ->assertJsonPath('error', 'The required platform permission is missing.');

        $this->getJson('/api/platform/auth/me', [
            'Authorization' => $headers['Authorization'],
        ])->assertOk()
            ->assertJsonPath('roles', [])
            ->assertJsonPath('permissions', []);
    }

    #[Test]
    public function platform_login_logout_and_password_change_are_audited_without_credentials(): void
    {
        $operator = $this->makeOperator();
        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $operator->email,
            'password' => 'platform-test-password',
        ])->assertOk();
        $token = $login->json('token');

        $this->postJson('/api/platform/auth/change-password', [
            'current_password' => 'platform-test-password',
            'new_password' => 'platform-updated-password',
            'new_password_confirmation' => 'platform-updated-password',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertSame(
            0,
            DB::connection('mysql')->table('platform_personal_access_tokens')
                ->where('tokenable_id', $operator->getKey())
                ->count(),
        );
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized();

        $newLogin = $this->postJson('/api/platform/auth/login', [
            'email' => $operator->email,
            'password' => 'platform-updated-password',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/platform/auth/logout', [], [
            'Authorization' => 'Bearer '.$newLogin->json('token'),
        ])->assertOk();

        $events = PlatformAuditEntry::on('mysql')->where('actor_id', $operator->getKey())->pluck('event')->all();
        $this->assertContains('platform.auth.login', $events);
        $this->assertContains('platform.auth.password_changed', $events);
        $this->assertContains('platform.auth.logout', $events);
        $auditPayload = json_encode(PlatformAuditEntry::on('mysql')->get(['before', 'after']));
        $this->assertStringNotContainsString('platform-test-password', $auditPayload);
        $this->assertStringNotContainsString('platform-updated-password', $auditPayload);
    }

    #[Test]
    public function bootstrap_requires_confirmation_and_can_assign_owner_only_once(): void
    {
        $owner = $this->makeOperator();
        $anotherOperator = $this->makeOperator();

        $this->artisan('platform:iam:bootstrap-owner', ['email' => $owner->email])
            ->assertExitCode(1);
        $this->assertDatabaseMissing('platform_roles', ['slug' => 'platform-owner'], 'mysql');

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => $owner->email,
            '--confirm' => true,
        ])->assertExitCode(0);
        $this->assertTrue($owner->fresh()->platformRoles()->where('slug', 'platform-owner')->exists());

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => $anotherOperator->email,
            '--confirm' => true,
        ])->assertExitCode(1);
        $this->assertFalse($anotherOperator->fresh()->platformRoles()->exists());
    }

    #[Test]
    public function bootstrap_rejects_an_inactive_target_operator(): void
    {
        $operator = $this->makeOperator();
        $operator->update(['is_active' => false]);

        $this->artisan('platform:iam:bootstrap-owner', [
            'email' => $operator->email,
            '--confirm' => true,
        ])->assertExitCode(1);

        $this->assertFalse($operator->fresh()->platformRoles()->exists());
    }

    #[Test]
    public function owner_can_create_and_deactivate_a_platform_user_with_a_role(): void
    {
        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());
        $userRole = $this->makeRole(['platform.users.view']);
        $headers = $this->headersFor($owner);

        $created = $this->postJson('/api/platform/users', [
            'name' => 'Platform Reader',
            'email' => 'platform-reader@example.test',
            'password' => 'platform-reader-password',
            'password_confirmation' => 'platform-reader-password',
            'is_active' => true,
            'role_ids' => [$userRole->getKey()],
        ], $headers)->assertCreated()
            ->assertJsonPath('user.is_active', true)
            ->assertJsonPath('user.platform_roles.0.id', $userRole->getKey());

        $userId = $created->json('user.id');
        $this->patchJson('/api/platform/users/'.$userId, [
            'name' => 'Inactive Platform Reader',
            'is_active' => false,
        ], $headers)->assertOk()
            ->assertJsonPath('user.name', 'Inactive Platform Reader')
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('platform_audit_entries', [
            'event' => 'platform.user.created',
            'subject_id' => (string) $userId,
        ], 'mysql');
        $audit = PlatformAuditEntry::on('mysql')->where('event', 'platform.user.created')->firstOrFail();
        $this->assertStringNotContainsString('platform-reader-password', json_encode($audit->after));
    }

    #[Test]
    public function deactivating_platform_user_revokes_only_its_platform_tokens_and_reactivation_does_not_restore_them(): void
    {
        config([
            'database.connections.tenant.driver' => 'sqlite',
            'database.connections.tenant.database' => ':memory:',
            'database.connections.tenant.prefix' => '',
            'database.connections.tenant.foreign_key_constraints' => true,
        ]);
        DB::purge('tenant');
        $tenantConnection = DB::connection('tenant');
        $this->assertSame('sqlite', $tenantConnection->getDriverName());
        $this->assertSame(':memory:', $tenantConnection->getConfig('database'));
        $this->assertSame(
            'sqlite',
            $tenantConnection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME),
            'Tenant token isolation fixture must use SQLite in memory.',
        );
        Schema::connection('tenant')->create('personal_access_tokens', function (Blueprint $table): void {
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
        $tenantTokenHash = hash('sha256', 'tenant-token-that-must-remain');
        $tenantConnection->table('personal_access_tokens')->insert([
            'tokenable_type' => \App\IAM\User::class,
            'tokenable_id' => 999,
            'name' => 'tenant-test',
            'token' => $tenantTokenHash,
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = $this->makeOperator();
        $target = $this->makeOperator();
        $other = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $ordinaryRole = $this->makeRole(['platform.users.view']);
        $owner->platformRoles()->attach($ownerRole->getKey());
        $target->platformRoles()->attach($ordinaryRole->getKey());
        $other->platformRoles()->attach($ordinaryRole->getKey());

        $targetTokens = [
            $target->createPlatformToken('target-one', ['platform:*'])->plainTextToken,
            $target->createPlatformToken('target-two', ['platform:*'])->plainTextToken,
        ];
        $otherToken = $other->createPlatformToken('other-user', ['platform:*'])->plainTextToken;
        $headers = $this->headersFor($owner);

        $this->patchJson('/api/platform/users/'.$target->getKey(), [
            'is_active' => false,
        ], $headers)->assertOk()
            ->assertJsonPath('user.is_active', false);

        $this->assertSame(
            0,
            DB::connection('mysql')->table('platform_personal_access_tokens')
                ->where('tokenable_type', PlatformUser::class)
                ->where('tokenable_id', $target->getKey())
                ->count(),
        );
        $this->assertSame(
            1,
            DB::connection('mysql')->table('platform_personal_access_tokens')
                ->where('tokenable_type', PlatformUser::class)
                ->where('tokenable_id', $other->getKey())
                ->count(),
        );
        $this->assertSame(1, $tenantConnection->table('personal_access_tokens')->count());

        foreach ($targetTokens as $oldToken) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/platform/auth/me', [
                'Authorization' => 'Bearer '.$oldToken,
            ])->assertUnauthorized();
        }

        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/platform/users/'.$target->getKey(), [
            'is_active' => true,
        ], $headers)->assertOk()
            ->assertJsonPath('user.is_active', true);

        foreach ($targetTokens as $oldToken) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/platform/auth/me', [
                'Authorization' => 'Bearer '.$oldToken,
            ])->assertUnauthorized();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer '.$otherToken,
        ])->assertOk()
            ->assertJsonPath('email', $other->email);
        $this->assertSame(1, $tenantConnection->table('personal_access_tokens')->count());
    }

    #[Test]
    public function owner_can_update_role_permissions_and_delete_an_unassigned_role(): void
    {
        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());
        $role = $this->makeRole(['platform.users.view']);
        $headers = $this->headersFor($owner);
        $permissionId = PlatformPermission::on('mysql')
            ->where('name', 'platform.roles.view')
            ->value('id');

        $this->patchJson('/api/platform/roles/'.$role->getKey(), [
            'name' => 'Renamed Platform Role',
        ], $headers)->assertOk()
            ->assertJsonPath('role.name', 'Renamed Platform Role');

        $this->putJson('/api/platform/roles/'.$role->getKey().'/permissions', [
            'permission_ids' => [$permissionId],
        ], $headers)->assertOk()
            ->assertJsonPath('role.permissions.0.id', $permissionId);

        $this->deleteJson('/api/platform/roles/'.$role->getKey(), [], $headers)->assertNoContent();
    }

    #[Test]
    public function last_active_platform_owner_cannot_be_deactivated(): void
    {
        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());

        $this->patchJson('/api/platform/users/'.$owner->getKey(), [
            'is_active' => false,
        ], $this->headersFor($owner))
            ->assertUnprocessable();
    }

    #[Test]
    public function rejecting_last_owner_deactivation_preserves_its_platform_pat(): void
    {
        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());
        $token = $owner->createPlatformToken('last-owner-test', ['platform:*'])->plainTextToken;
        $tokenId = explode('|', $token, 2)[0];
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->patchJson('/api/platform/users/'.$owner->getKey(), [
            'is_active' => false,
        ], $headers)->assertUnprocessable();

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertDatabaseHas('platform_personal_access_tokens', [
            'id' => $tokenId,
            'tokenable_type' => PlatformUser::class,
            'tokenable_id' => $owner->getKey(),
        ], 'mysql');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/platform/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('id', $owner->getKey());
    }

    #[Test]
    public function deactivation_preserves_one_active_platform_owner(): void
    {
        $firstOwner = $this->makeOperator();
        $secondOwner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $firstOwner->platformRoles()->attach($ownerRole->getKey());
        $secondOwner->platformRoles()->attach($ownerRole->getKey());
        $headers = $this->headersFor($firstOwner);

        $this->patchJson('/api/platform/users/'.$secondOwner->getKey(), [
            'is_active' => false,
        ], $headers)->assertOk();
        $this->patchJson('/api/platform/users/'.$firstOwner->getKey(), [
            'is_active' => false,
        ], $headers)->assertUnprocessable();

        $this->assertSame(
            1,
            PlatformUser::on('mysql')
                ->where('is_active', true)
                ->whereHas('platformRoles', fn ($query) => $query->where('slug', 'platform-owner'))
                ->count(),
        );
    }

    #[Test]
    public function operator_cannot_assign_a_more_privileged_role_to_themselves(): void
    {
        $operator = $this->makeOperator();
        $currentRole = $this->makeRole(['platform.users.assign-roles', 'platform.roles.view']);
        $elevatedRole = $this->makeRole(['platform.users.view']);
        $operator->platformRoles()->attach($currentRole->getKey());

        $this->putJson('/api/platform/users/'.$operator->getKey().'/roles', [
            'role_ids' => [$currentRole->getKey(), $elevatedRole->getKey()],
        ], $this->headersFor($operator))->assertForbidden();
    }

    #[Test]
    public function system_owner_role_cannot_be_assigned_through_user_management_api(): void
    {
        $owner = $this->makeOperator();
        $ownerRole = $this->makeRole(
            PlatformPermission::on('mysql')->pluck('name')->all(),
            'platform-owner',
            true,
        );
        $owner->platformRoles()->attach($ownerRole->getKey());

        $this->postJson('/api/platform/users', [
            'name' => 'New Operator',
            'email' => 'new-operator@example.test',
            'password' => 'new-platform-password',
            'password_confirmation' => 'new-platform-password',
            'is_active' => false,
            'role_ids' => [$ownerRole->getKey()],
        ], $this->headersFor($owner))->assertUnprocessable();

        $this->assertDatabaseMissing('platform_users', [
            'email' => 'new-operator@example.test',
        ], 'mysql');
    }

    private function makeOperator(): PlatformUser
    {
        return PlatformUser::on('mysql')->create([
            'name' => 'Platform IAM Test Operator',
            'email' => 'platform-iam-'.uniqid().'@example.test',
            'password' => Hash::make('platform-test-password'),
            'is_active' => true,
        ]);
    }

    /**
     * @param array<int, string> $permissionNames
     */
    private function makeRole(
        array $permissionNames,
        string $slug = 'test-role',
        bool $system = false,
    ): PlatformRole {
        $role = PlatformRole::on('mysql')->create([
            'name' => 'Platform test role',
            'slug' => $system ? $slug : $slug.'-'.uniqid(),
            'description' => 'Feature test role',
            'is_system' => $system,
        ]);
        $permissionIds = PlatformPermission::on('mysql')
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->all();
        $role->permissions()->sync($permissionIds);

        return $role;
    }

    /**
     * @return array{Authorization: string}
     */
    private function headersFor(PlatformUser $operator): array
    {
        $token = $operator->createPlatformToken('platform-iam-test', ['platform:*'])->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }
}
