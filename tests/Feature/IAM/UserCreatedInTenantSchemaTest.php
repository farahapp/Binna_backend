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
 * Verifies that User is created in the correct tenant schema (OD-001, A2).
 */
class UserCreatedInTenantSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantSchema();
        $this->cleanupUsers();
    }

    protected function tearDown(): void
    {
        $this->cleanupUsers();
        parent::tearDown();
    }

    /** @test */
    public function user_is_created_in_tenant_schema(): void
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Tenant User';
        $user->email    = 'tenant-'.uniqid().'@example.com';
        $user->password = Hash::make('password');
        $user->save();

        $found = User::on('tenant')->where('email', $user->email)->first();
        $this->assertNotNull($found);
        $this->assertEquals('Tenant User', $found->name);
    }

    /** @test */
    public function user_id_is_uuid(): void
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'UUID User';
        $user->email    = 'uuid-'.uniqid().'@example.com';
        $user->password = Hash::make('password');
        $user->save();

        // UUID v4 or v7 (Laravel 11+ uses v7 by default with HasUuids — TODEC-003 specifies UUID v4 format).
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $user->id,
            'User ID must be a valid UUID'
        );
    }

    private function setUpTenantSchema(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/shared', '--force' => true]);
        }

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => 'schema-test-tenant',
            'name'        => 'Schema Test Tenant',
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
    }

    private function cleanupUsers(): void
    {
        try {
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', 'schema-test-tenant')->delete();
        } catch (\Throwable) {}
    }
}
