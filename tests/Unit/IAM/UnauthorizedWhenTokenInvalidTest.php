<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies 401 is returned when PAT is missing or invalid (HB-1).
 */
class UnauthorizedWhenTokenInvalidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantForUnauth();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('mysql')->table('tenants')->where('slug', 'test-tenant')->delete();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    /** @test */
    public function returns_401_when_no_token_provided(): void
    {
        $response = $this->postJson('/api/auth/logout', [], [
            'X-Tenant' => 'test-tenant',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function returns_401_when_token_is_invalid(): void
    {
        $response = $this->postJson('/api/auth/logout', [], [
            'Authorization' => 'Bearer invalid-token-xyz',
            'X-Tenant'      => 'test-tenant',
        ]);

        $response->assertStatus(401);
    }

    private function setUpTenantForUnauth(): void
    {
        if (! Schema::connection('mysql')->hasTable('tenants')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/shared', '--force' => true]);
        }

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => 'test-tenant',
            'name'        => 'Test Tenant',
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
}
