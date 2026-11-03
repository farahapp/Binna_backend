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

        // Step 1: Remove any row this Suite previously inserted (by slug).
        DB::connection('mysql')->table('tenants')
            ->where('slug', 'tenant-a')
            ->delete();

        // Step 2: Guard — detect a foreign stale row sharing our schema_name.
        $conflict = DB::connection('mysql')->table('tenants')
            ->where('schema_name', 'binaa2_testing')
            ->where('slug', '!=', 'tenant-a')
            ->first();

        if ($conflict !== null) {
            $this->fail(
                "FIX-A-CODE guard: schema_name='binaa2_testing' is already " .
                "claimed by slug='{$conflict->slug}' (id={$conflict->id}). " .
                "A previous test run left a stale row. " .
                "Run: DELETE FROM tenants WHERE id={$conflict->id} AND slug='{$conflict->slug}';"
            );
        }

        // Step 3: Insert our own row.
        DB::connection('mysql')->table('tenants')->insert([
            'slug'        => 'tenant-a',
            'name'        => 'Tenant A',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', config('database.connections.mysql.host', '127.0.0.1'));
        Config::set('database.connections.tenant.port', config('database.connections.mysql.port', '3307'));
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

        // AuthController::logout() calls AuditService::logEvent() which writes
        // to audit_entries on the 'tenant' connection. The table must exist in
        // binaa2_testing (port 3307) for the logout request to succeed.
        if (! Schema::connection('tenant')->hasTable('audit_entries')) {
            Schema::connection('tenant')->create('audit_entries', function ($table) {
                $table->uuid('id')->primary();
                $table->string('event');
                $table->string('actor_id')->nullable();
                $table->string('tenant_slug');
                $table->string('subject_type')->nullable();
                $table->string('subject_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamp('created_at')->nullable();
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
