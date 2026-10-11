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
 * Verifies that Logout revokes the PAT server-side (HB-1).
 */
class LogoutRevokesPatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantSchema();
    }

    /** @test */
    public function logout_revokes_pat_in_backend(): void
    {
        $user      = $this->createTenantUser('logout@example.com', 'secret123');
        $newToken  = $user->createToken('api');
        $plainText = $newToken->plainTextToken;
        $tokenId   = $newToken->accessToken->id;

        $response = $this->postJson('/api/auth/logout', [], [
            'Authorization' => 'Bearer '.$plainText,
            'X-Tenant'      => 'test-tenant',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('message', 'Logged out successfully.');

        // Verify the PAT was deleted from the database (server-side revocation — HB-1).
        $tokenExists = \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('personal_access_tokens')
            ->where('id', $tokenId)
            ->exists();

        $this->assertFalse($tokenExists, 'PAT must be deleted from the database after logout (HB-1).');
    }

    private function setUpTenantSchema(): void
    {
        // RefreshDatabase already ran all migrations and enrolled both the mysql and tenant
        // connections in a transaction via TestCase::connectionsToTransact(). The tenant
        // connection is pre-defined in config/database.php pointing at binaa2_testing.
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

    private function cleanupIamTables(): void
    {
        try {
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', 'test-tenant')->delete();
        } catch (\Throwable) {
            // Tables may not exist yet — ignore.
        }
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
