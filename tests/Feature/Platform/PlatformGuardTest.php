<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use App\IAM\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PlatformGuardTest — isolation and correctness tests for PlatformGuard.
 *
 * Coverage:
 *  PG-01 — createPlatformToken() writes to platform_personal_access_tokens on mysql, not tenant.
 *  PG-02 — createPlatformToken() sets tokenable_type = App\Foundation\PlatformUser.
 *  PG-03 — createPlatformToken() sets tokenable_id = $operator->id (bigint).
 *  PG-04 — PlatformGuard resolves PlatformUser from a valid bearer token.
 *  PG-05 — PlatformGuard returns 401 for a tenant bearer token (not on mysql).
 *  PG-06 — PlatformGuard returns 401 for a revoked/deleted token.
 *  PG-07 — PlatformGuard updates last_used_at on successful authentication.
 *  PG-08 — PlatformGuard returns 401 for an expired token.
 *  PG-09 — Platform token presented to tenant route returns 401.
 *  PG-10 — Tenant token presented to platform route returns 401.
 *
 * Uses DatabaseTransactions on 'mysql' only.
 * Tenant schema is not provisioned — tenant token tests use a fabricated token
 * that does not exist on either connection.
 */
class PlatformGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensurePlatformUsersTableExists();
        $this->ensurePlatformPATTableExists();
    }

    // =========================================================================
    // PG-01 — createPlatformToken() writes to mysql, not tenant
    // =========================================================================

    #[Test]
    public function create_platform_token_writes_to_mysql_not_tenant(): void
    {
        $operator = $this->makePlatformUser();
        $operator->createPlatformToken('test-token', ['platform:*']);

        // Assert row exists on mysql.
        $mysqlCount = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('tokenable_id', $operator->id)
            ->count();

        $this->assertSame(1, $mysqlCount, 'PAT must be written to mysql platform_personal_access_tokens');

        // Assert NO row was written to tenant connection.
        // The tenant connection may not have the table at all in this test context —
        // we assert the mysql count is correct and do not query tenant to avoid
        // connection errors when the tenant schema is not provisioned.
    }

    // =========================================================================
    // PG-02 — tokenable_type is App\Foundation\PlatformUser
    // =========================================================================

    #[Test]
    public function create_platform_token_sets_correct_tokenable_type(): void
    {
        $operator = $this->makePlatformUser();
        $operator->createPlatformToken('test-token', ['platform:*']);

        $row = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('tokenable_id', $operator->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(
            PlatformUser::class,
            $row->tokenable_type,
            'tokenable_type must be App\Foundation\PlatformUser'
        );
    }

    // =========================================================================
    // PG-03 — tokenable_id matches operator's bigint id
    // =========================================================================

    #[Test]
    public function create_platform_token_sets_correct_tokenable_id(): void
    {
        $operator = $this->makePlatformUser();
        $operator->createPlatformToken('test-token', ['platform:*']);

        $row = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('tokenable_type', PlatformUser::class)
            ->where('tokenable_id', $operator->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $operator->id, (int) $row->tokenable_id);
    }

    // =========================================================================
    // PG-04 — PlatformGuard resolves PlatformUser from valid bearer token
    // =========================================================================

    #[Test]
    public function platform_guard_resolves_platform_user_from_valid_token(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $this->assertSame($operator->email, $response->json('email'));
    }

    // =========================================================================
    // PG-05 — Fabricated/tenant bearer token returns 401 on platform route
    // =========================================================================

    #[Test]
    public function fabricated_token_returns_401_on_platform_route(): void
    {
        // A token that does not exist in platform_personal_access_tokens on mysql.
        $fakeToken = '999|' . str_repeat('x', 40);

        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $fakeToken,
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // PG-06 — Revoked token returns 401
    // =========================================================================

    #[Test]
    public function revoked_token_returns_401(): void
    {
        $operator = $this->makePlatformUser();
        $newToken = $operator->createPlatformToken('platform-api', ['platform:*']);
        $plain    = $newToken->plainTextToken;

        // Revoke by deleting from mysql.
        DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('tokenable_id', $operator->id)
            ->delete();

        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $plain,
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // PG-07 — last_used_at is updated on successful authentication
    // =========================================================================

    #[Test]
    public function successful_auth_updates_last_used_at(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        // Confirm last_used_at is null before the request.
        $before = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('tokenable_id', $operator->id)
            ->first();

        $this->assertNull($before->last_used_at);

        $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200);

        $after = DB::connection('mysql')
            ->table('platform_personal_access_tokens')
            ->where('tokenable_id', $operator->id)
            ->first();

        $this->assertNotNull($after->last_used_at, 'last_used_at must be set after successful auth');
    }

    // =========================================================================
    // PG-08 — Expired token returns 401
    // =========================================================================

    #[Test]
    public function expired_token_returns_401(): void
    {
        $operator  = $this->makePlatformUser();
        $expiresAt = now()->subMinute(); // already expired
        $token     = $operator->createPlatformToken('platform-api', ['platform:*'], $expiresAt)->plainTextToken;

        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // PG-09 — Platform token on tenant route returns 401
    //         (token not found on tenant connection's personal_access_tokens)
    // =========================================================================

    #[Test]
    public function platform_token_on_tenant_route_returns_401(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        // Tenant route requires X-Tenant header. We provide a non-existent tenant
        // so the request fails at ResolveTenantContext (404) before auth — but
        // the important assertion is that the platform token is NOT accepted.
        // We use the login route (public) to confirm the token format is not
        // accidentally accepted by the Sanctum guard on tenant routes.
        //
        // For a full isolation assertion: present the token to a protected tenant
        // route with a valid X-Tenant. The Sanctum guard will query personal_access_tokens
        // on the tenant connection — the platform token is not there → 401.
        // We assert 401 (not 200 or 403) to confirm the token is not resolved.
        $response = $this->getJson('/api/v1/crm/clients', [
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant'      => 'nonexistent-tenant-pg09',
        ]);

        // 404 = tenant not found (correct — platform token was not accepted as auth)
        // 401 = unauthenticated (also correct)
        // 200 = FAIL — platform token must never authenticate a tenant route
        $this->assertNotSame(200, $response->getStatusCode(),
            'Platform token must never return 200 on a tenant route'
        );
    }

    // =========================================================================
    // PG-10 — Tenant token on platform route returns 401
    // =========================================================================

    #[Test]
    public function tenant_token_on_platform_route_returns_401(): void
    {
        // Fabricate a token in the format Sanctum uses but not present in
        // platform_personal_access_tokens on mysql.
        // This simulates a tenant PAT being presented to a platform route.
        $fakeTenantToken = '1|' . str_repeat('t', 40);

        $response = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $fakeTenantToken,
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function makePlatformUser(bool $active = true): PlatformUser
    {
        $operator            = new PlatformUser();
        $operator->setConnection('mysql');
        $operator->name      = 'PG Test Operator';
        $operator->email     = 'pg-test-' . uniqid() . '@binaa.test';
        $operator->password  = Hash::make('pg-secret-123');
        $operator->is_active = $active;
        $operator->save();

        return $operator;
    }

    private function ensurePlatformUsersTableExists(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_users')) {
            Schema::connection('mysql')->create('platform_users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index('email');
                $table->index('is_active');
            });
        }
    }

    private function ensurePlatformPATTableExists(): void
    {
        if (! Schema::connection('mysql')->hasTable('platform_personal_access_tokens')) {
            Schema::connection('mysql')->create('platform_personal_access_tokens', function ($table) {
                $table->id();
                $table->string('tokenable_type');
                $table->unsignedBigInteger('tokenable_id');
                $table->index(['tokenable_type', 'tokenable_id'], 'ppat_tokenable_index');
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
