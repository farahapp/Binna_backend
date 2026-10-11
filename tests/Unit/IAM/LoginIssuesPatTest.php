<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Verifies that Login issues a Sanctum PAT (HB-1).
 */
class LoginIssuesPatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantSchema();
    }

    /** @test */
    public function login_issues_pat_for_valid_credentials(): void
    {
        $this->createTenantUser('test@example.com', 'secret123');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'secret123',
        ], ['X-Tenant' => 'test-tenant']);

        $response->assertStatus(200)
                 ->assertJsonStructure(['token', 'token_type'])
                 ->assertJsonPath('token_type', 'Bearer');

        $this->assertNotEmpty($response->json('token'));
    }

    /** @test */
    public function login_rejects_invalid_credentials(): void
    {
        $this->createTenantUser('test@example.com', 'secret123');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'wrong-password',
        ], ['X-Tenant' => 'test-tenant']);

        $response->assertStatus(422);
    }

    private function setUpTenantSchema(): void
    {
        // RefreshDatabase already ran all migrations (users, personal_access_tokens, tenants, etc.)
        // and enrolled both the mysql and tenant connections in a transaction via
        // TestCase::connectionsToTransact(). The tenant connection is pre-defined in
        // config/database.php pointing at binaa2_testing, so no runtime Config::set is needed.
        //
        // We only insert the test tenant record so the middleware can resolve it.
        // DB::purge('tenant') is intentionally NOT called here — purging would destroy
        // the transaction that RefreshDatabase opened on the tenant connection.

        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => 'test-tenant',
            'name'        => 'Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createTenantUser(string $email, string $password): User
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Test User';
        $user->email    = $email;
        $user->password = Hash::make($password);
        $user->save();
        return $user;
    }
}
