<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies Role assignment and Permission check (A2).
 */
class RoleAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantConnection();
        $this->cleanupRoleTables();
    }

    protected function tearDown(): void
    {
        $this->cleanupRoleTables();
        parent::tearDown();
    }

    /** @test */
    public function user_can_be_assigned_a_role(): void
    {
        $user = $this->makeTenantUser();
        $role = Role::on('tenant')->create(['name' => 'admin-'.uniqid(), 'guard_name' => 'api']);

        $user->assignRole($role);

        $this->assertTrue($user->hasRole($role->name));
    }

    /** @test */
    public function user_has_permission_through_role(): void
    {
        $user       = $this->makeTenantUser();
        $role       = Role::on('tenant')->create(['name' => 'editor-'.uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'edit-posts-'.uniqid(), 'guard_name' => 'api']);

        $role->givePermission($permission);
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission($permission->name));
    }

    private function setUpTenantConnection(): void
    {
        $this->ensureSharedTablesExist();

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => 'role-test-tenant',
            'name'        => 'Role Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', '127.0.0.1');
        Config::set('database.connections.tenant.port', '3306');
        Config::set('database.connections.tenant.database', 'binaa2_testing');
        Config::set('database.connections.tenant.username', config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password', config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset', 'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix', '');
        Config::set('database.connections.tenant.strict', true);
        DB::purge('tenant');

        $this->ensureIamTablesExist();
    }

    private function ensureSharedTablesExist(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/shared', '--force' => true]);
        }
    }

    private function ensureIamTablesExist(): void
    {
        if (! Schema::connection('tenant')->hasTable('users')) {
            Schema::connection('tenant')->create('users', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::connection('tenant')->hasTable('roles')) {
            Schema::connection('tenant')->create('roles', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        if (! Schema::connection('tenant')->hasTable('permissions')) {
            Schema::connection('tenant')->create('permissions', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        if (! Schema::connection('tenant')->hasTable('model_has_roles')) {
            Schema::connection('tenant')->create('model_has_roles', function ($table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('role_has_permissions')) {
            Schema::connection('tenant')->create('role_has_permissions', function ($table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
        }

        if (! Schema::connection('tenant')->hasTable('personal_access_tokens')) {
            Schema::connection('tenant')->create('personal_access_tokens', function ($table) {
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

    private function cleanupRoleTables(): void
    {
        try {
            DB::connection('tenant')->table('role_has_permissions')->delete();
            DB::connection('tenant')->table('model_has_roles')->delete();
            DB::connection('tenant')->table('permissions')->delete();
            DB::connection('tenant')->table('roles')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', 'role-test-tenant')->delete();
        } catch (\Throwable) {
            // ignore
        }
    }

    private function makeTenantUser(): User
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Role Test User';
        $user->email    = 'role-'.uniqid().'@example.com';
        $user->password = Hash::make('password');
        $user->save();
        return $user;
    }
}
