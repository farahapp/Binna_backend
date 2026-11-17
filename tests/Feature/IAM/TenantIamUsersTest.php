<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\IAM\Role;
use App\IAM\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Wave E.4 — Tenant IAM users list/create + role-assign guards.
 */
class TenantIamUsersTest extends TestCase
{
    use DatabaseTransactions;

    private string $tenantSlug = 'sep-test-tenant';

    private string $tenantSchema = 'binaa2_testing';

    protected function connectionsToTransact(): array
    {
        return ['tenant'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSharedTablesExist();
        $this->ensureTenantRecord();
        $this->configureTenantConnection();
        $this->ensureIamTablesExist();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function guest_cannot_list_users(): void
    {
        $this->getJson('/api/iam/users', ['X-Tenant' => $this->tenantSlug])
            ->assertStatus(401);
    }

    #[Test]
    public function plain_user_cannot_list_users(): void
    {
        $user = $this->makeTenantUser();
        $token = $user->createToken('api')->plainTextToken;

        $this->getJson('/api/iam/users', [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant' => $this->tenantSlug,
        ])->assertStatus(403);
    }

    #[Test]
    public function tenant_admin_can_list_and_create_user(): void
    {
        $admin = $this->makeTenantUser('admin-'.uniqid().'@example.test');
        $adminRole = Role::on('tenant')->firstOrCreate(
            ['name' => 'tenant_admin'],
            ['guard_name' => 'api']
        );
        $admin->assignRole($adminRole);
        $token = $admin->createToken('api')->plainTextToken;

        $this->getJson('/api/iam/users', [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant' => $this->tenantSlug,
        ])->assertOk()->assertJsonStructure(['data', 'meta']);

        $email = 'new-user-'.uniqid().'@example.test';
        $create = $this->postJson('/api/iam/users', [
            'name' => 'New User',
            'email' => $email,
            'password' => 'SecurePass#12345',
            'password_confirmation' => 'SecurePass#12345',
            'role_ids' => [],
        ], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant' => $this->tenantSlug,
        ]);

        $create->assertCreated();
        $this->assertSame($email, $create->json('user.email'));
        $this->assertTrue(User::on('tenant')->where('email', $email)->exists());
    }

    #[Test]
    public function non_admin_cannot_assign_tenant_admin_role(): void
    {
        $actor = $this->makeTenantUser('actor-'.uniqid().'@example.test');
        $actor->grantPermission('iam:users:assign_roles');
        $actor->grantPermission('iam:users:view');
        $token = $actor->createToken('api')->plainTextToken;

        $target = $this->makeTenantUser('target-'.uniqid().'@example.test');
        $adminRole = Role::on('tenant')->firstOrCreate(
            ['name' => 'tenant_admin'],
            ['guard_name' => 'api']
        );

        $this->putJson('/api/iam/users/'.$target->id.'/roles', [
            'role_ids' => [$adminRole->id],
        ], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant' => $this->tenantSlug,
        ])->assertStatus(403);

        $this->assertFalse($target->fresh()->hasRole('tenant_admin'));
    }

    private function makeTenantUser(string $email = ''): User
    {
        if ($email === '') {
            $email = 'user-'.uniqid().'@example.test';
        }
        $user = new User;
        $user->setConnection('tenant');
        $user->name = 'Test User';
        $user->email = $email;
        $user->password = Hash::make('password-not-used');
        $user->save();

        return $user;
    }

    private function ensureSharedTablesExist(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Schema::connection('mysql')->create('tenants', function ($table): void {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->string('schema_name')->nullable();
                $table->string('status')->default('active');
                $table->string('legal_type')->nullable();
                $table->string('activity_type')->nullable();
                $table->timestamps();
            });
        }
    }

    private function ensureTenantRecord(): void
    {
        $exists = DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->exists();
        if (! $exists) {
            DB::connection('mysql')->table('tenants')->insert([
                'slug' => $this->tenantSlug,
                'name' => 'SEP Test Tenant',
                'schema_name' => $this->tenantSchema,
                'status' => 'active',
                'legal_type' => 'organization',
                'activity_type' => 'contractor',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->update([
                'schema_name' => $this->tenantSchema,
                'status' => 'active',
            ]);
        }
    }

    private function configureTenantConnection(): void
    {
        Config::set('database.connections.tenant.database', $this->tenantSchema);
        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    private function ensureIamTablesExist(): void
    {
        if (! Schema::connection('tenant')->hasTable('users')) {
            Schema::connection('tenant')->create('users', function ($table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }
        if (! Schema::connection('tenant')->hasTable('roles')) {
            Schema::connection('tenant')->create('roles', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }
        if (! Schema::connection('tenant')->hasTable('permissions')) {
            Schema::connection('tenant')->create('permissions', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }
        if (! Schema::connection('tenant')->hasTable('model_has_roles')) {
            Schema::connection('tenant')->create('model_has_roles', function ($table): void {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
        }
        if (! Schema::connection('tenant')->hasTable('role_has_permissions')) {
            Schema::connection('tenant')->create('role_has_permissions', function ($table): void {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }
        if (! Schema::connection('tenant')->hasTable('personal_access_tokens')) {
            Schema::connection('tenant')->create('personal_access_tokens', function ($table): void {
                $table->id();
                $table->uuidMorphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }
}
