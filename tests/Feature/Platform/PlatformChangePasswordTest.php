<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Foundation\PlatformUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PlatformChangePasswordTest — HTTP tests for POST /api/platform/auth/change-password.
 *
 * Coverage:
 *  PCP-01 — Authenticated operator can change password successfully (200).
 *  PCP-02 — New password verifies correctly after change.
 *  PCP-03 — Old password is rejected after change (login returns 422).
 *  PCP-04 — Wrong current_password returns 422.
 *  PCP-05 — Mismatched new_password_confirmation returns 422.
 *  PCP-06 — new_password shorter than 8 chars returns 422.
 *  PCP-07 — Unauthenticated request returns 401.
 *  PCP-08 — Inactive operator is rejected by middleware (403).
 *  PCP-09 — new_password identical to current_password returns 422.
 *  PCP-10 — Response body does NOT contain password or hash.
 *  PCP-11 — Existing PATs are NOT revoked after password change.
 *
 * Uses DatabaseTransactions on 'mysql' only — no tenant schema touched.
 * Never connects to production database.
 */
class PlatformChangePasswordTest extends TestCase
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
    // PCP-01 — Successful password change returns 200
    // =========================================================================

    #[Test]
    public function authenticated_operator_can_change_password(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Password changed successfully.']);
    }

    // =========================================================================
    // PCP-02 — New password verifies correctly in DB after change
    // =========================================================================

    #[Test]
    public function new_password_verifies_correctly_after_change(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        $storedHash = DB::connection('mysql')
            ->table('platform_users')
            ->where('id', $operator->id)
            ->value('password');

        $this->assertTrue(Hash::check('NewSecure#2026!', $storedHash));
    }

    // =========================================================================
    // PCP-03 — Old password is rejected after change
    // =========================================================================

    #[Test]
    public function old_password_is_rejected_after_change(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        // Attempt login with old password — must fail
        $loginResponse = $this->postJson('/api/platform/auth/login', [
            'email'    => $operator->email,
            'password' => 'platform-secret-123',
        ]);

        $loginResponse->assertStatus(422);
    }

    // =========================================================================
    // PCP-04 — Wrong current_password returns 422
    // =========================================================================

    #[Test]
    public function wrong_current_password_returns_422(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'wrong-current-password',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    // =========================================================================
    // PCP-05 — Mismatched new_password_confirmation returns 422
    // =========================================================================

    #[Test]
    public function mismatched_confirmation_returns_422(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'DifferentPassword#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['new_password']);
    }

    // =========================================================================
    // PCP-06 — new_password shorter than 8 chars returns 422
    // =========================================================================

    #[Test]
    public function short_new_password_returns_422(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'short',
            'new_password_confirmation' => 'short',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['new_password']);
    }

    // =========================================================================
    // PCP-07 — Unauthenticated request returns 401
    // =========================================================================

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // PCP-08 — Inactive operator is rejected by middleware (403)
    // =========================================================================

    #[Test]
    public function inactive_operator_is_rejected_with_403(): void
    {
        $operator = $this->makePlatformUser(active: false);
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // PCP-09 — new_password identical to current_password returns 422
    // =========================================================================

    #[Test]
    public function new_password_same_as_current_returns_422(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'platform-secret-123',
            'new_password_confirmation' => 'platform-secret-123',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['new_password']);
    }

    // =========================================================================
    // PCP-10 — Response body does NOT contain password or hash
    // =========================================================================

    #[Test]
    public function response_does_not_contain_password_or_hash(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        $response = $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('platform-secret-123', $body);
        $this->assertStringNotContainsString('NewSecure#2026!', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    // =========================================================================
    // PCP-11 — Existing PATs are NOT revoked after password change
    // =========================================================================

    #[Test]
    public function existing_pats_are_not_revoked_after_password_change(): void
    {
        $operator = $this->makePlatformUser();
        $token    = $operator->createPlatformToken('platform-api', ['platform:*'])->plainTextToken;

        // Change password
        $this->postJson('/api/platform/auth/change-password', [
            'current_password'          => 'platform-secret-123',
            'new_password'              => 'NewSecure#2026!',
            'new_password_confirmation' => 'NewSecure#2026!',
        ], ['Authorization' => 'Bearer ' . $token]);

        // Same token should still work for /me
        $meResponse = $this->getJson('/api/platform/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $meResponse->assertStatus(200);
        $this->assertSame($operator->email, $meResponse->json('email'));
    }

    // =========================================================================
    // Private helpers — identical pattern to PlatformAuthTest
    // =========================================================================

    private function makePlatformUser(bool $active = true): PlatformUser
    {
        $operator = new PlatformUser();
        $operator->setConnection('mysql');
        $operator->name      = 'Test Platform Operator';
        $operator->email     = 'platform-test-' . uniqid() . '@binaa.test';
        $operator->password  = Hash::make('platform-secret-123');
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
