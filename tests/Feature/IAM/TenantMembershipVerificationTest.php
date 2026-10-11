<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\IAM\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies Tenant Membership: authenticated user can access own tenant (OD-001).
 */
class TenantMembershipVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenants();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', 'tenant-a')->delete();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    /** @test */
    public function authenticated_user_can_access_own_tenant(): void
    {
        $user  = $this->createUserInTenant();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->postJson('/api/auth/logout', [], [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant'      => 'tenant-a',
        ]);

        $response->assertStatus(200);
    }

    private function setUpTenants(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/shared', '--force' => true]);
        }

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => 'tenant-a',
            'name'        => 'Tenant A',
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

    private function createUserInTenant(): User
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Member User';
        $user->email    = 'member-'.uniqid().'@example.com';
        $user->password = Hash::make('password');
        $user->save();
        return $user;
    }
}
