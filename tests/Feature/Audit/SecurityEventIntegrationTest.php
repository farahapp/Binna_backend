<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditEntry;
use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/**
 * Feature tests for TASK-001C Unit 3 — Security Event Integration.
 *
 * Verifies that Login, Logout, and Permission Change create correct AuditEntry records.
 *
 * Verification points (from prompt §10):
 *  1.  Successful login creates an audit record.
 *  2.  Audit actor is correct (login).
 *  3.  Audit tenant is correct (login).
 *  4.  Failed login behavior follows the documented contract.
 *  5.  Logout creates an audit record.
 *  6.  Audit actor is correct (logout).
 *  7.  Token revocation still occurs.
 *  8.  Tenant is correct (logout).
 *  9.  Permission change creates an audit record.
 *  10. Actor is the user performing the change.
 *  11. Affected subject is correct.
 *  12. Before state is captured.
 *  13. After state is captured.
 *  14. Tenant is correct (permission change).
 *  15. Audit records remain in the resolved tenant schema.
 *  16. No shared-schema audit record is created (structural — migration path).
 *  17. Integration-created AuditEntry remains immutable.
 *
 * Cross-tenant verification (§11):
 *  - tenant_slug in AuditEntry comes from resolved TenantContext, not caller input.
 */
class SecurityEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenantRecord();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function setUpTenantRecord(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => 'test-tenant',
            'name'        => 'Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createTenantUser(string $email = 'user@example.com', string $password = 'secret123'): User
    {
        $user = new User();
        $user->setConnection('tenant');
        $user->name     = 'Test User';
        $user->email    = $email;
        $user->password = Hash::make($password);
        $user->save();
        return $user;
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant' => 'test-tenant'];
    }

    private function authHeaders(string $token): array
    {
        return array_merge($this->tenantHeaders(), ['Authorization' => 'Bearer '.$token]);
    }

    // -------------------------------------------------------------------------
    // VP1-3 — Successful login creates audit record with correct actor/tenant
    // -------------------------------------------------------------------------

    /** @test */
    public function successful_login_creates_audit_entry(): void
    {
        $user = $this->createTenantUser('login@example.com', 'secret123');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'login@example.com',
            'password' => 'secret123',
        ], $this->tenantHeaders());

        $response->assertStatus(200);

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.login')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($entry, 'AuditEntry for auth.login must exist');
    }

    /** @test */
    public function successful_login_audit_has_correct_actor(): void
    {
        $user = $this->createTenantUser('actor@example.com', 'secret123');

        $this->postJson('/api/auth/login', [
            'email'    => 'actor@example.com',
            'password' => 'secret123',
        ], $this->tenantHeaders());

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.login')
            ->latest('created_at')
            ->first();

        $this->assertSame((string) $user->id, $entry->actor_id);
    }

    /** @test */
    public function successful_login_audit_has_correct_tenant(): void
    {
        $this->createTenantUser('tenant@example.com', 'secret123');

        $this->postJson('/api/auth/login', [
            'email'    => 'tenant@example.com',
            'password' => 'secret123',
        ], $this->tenantHeaders());

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.login')
            ->latest('created_at')
            ->first();

        // tenant_slug must come from resolved TenantContext — not arbitrary caller input.
        $this->assertSame('test-tenant', $entry->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // VP4 — Failed login follows documented contract
    // -------------------------------------------------------------------------

    /** @test */
    public function failed_login_creates_audit_entry_and_rejects_request(): void
    {
        $this->createTenantUser('fail@example.com', 'correct-password');

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'fail@example.com',
            'password' => 'wrong-password',
        ], $this->tenantHeaders());

        // Authentication is rejected — authority result is preserved.
        $response->assertStatus(422);

        // Failed login audit record must exist.
        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.login_failed')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($entry, 'AuditEntry for auth.login_failed must exist');
        $this->assertNull($entry->actor_id, 'Failed login actor_id must be null');
        $this->assertSame('test-tenant', $entry->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // VP5-8 — Logout creates audit record, token revoked, correct actor/tenant
    // -------------------------------------------------------------------------

    /** @test */
    public function logout_creates_audit_entry(): void
    {
        $user  = $this->createTenantUser('logout@example.com', 'secret123');
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->postJson('/api/auth/logout', [], $this->authHeaders($token));

        $response->assertStatus(200);

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.logout')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($entry, 'AuditEntry for auth.logout must exist');
    }

    /** @test */
    public function logout_audit_has_correct_actor(): void
    {
        $user  = $this->createTenantUser('logout-actor@example.com', 'secret123');
        $token = $user->createToken('api')->plainTextToken;

        $this->postJson('/api/auth/logout', [], $this->authHeaders($token));

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.logout')
            ->latest('created_at')
            ->first();

        $this->assertSame((string) $user->id, $entry->actor_id);
    }

    /** @test */
    public function logout_still_revokes_token(): void
    {
        $user      = $this->createTenantUser('revoke@example.com', 'secret123');
        $newToken  = $user->createToken('api');
        $plainText = $newToken->plainTextToken;
        $tokenId   = $newToken->accessToken->id;

        $this->postJson('/api/auth/logout', [], $this->authHeaders($plainText));

        $tokenExists = DB::connection('tenant')
            ->table('personal_access_tokens')
            ->where('id', $tokenId)
            ->exists();

        $this->assertFalse($tokenExists, 'PAT must be deleted after logout (HB-1)');
    }

    /** @test */
    public function logout_audit_has_correct_tenant(): void
    {
        $user  = $this->createTenantUser('logout-tenant@example.com', 'secret123');
        $token = $user->createToken('api')->plainTextToken;

        $this->postJson('/api/auth/logout', [], $this->authHeaders($token));

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.logout')
            ->latest('created_at')
            ->first();

        $this->assertSame('test-tenant', $entry->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // VP9-14 — Permission change creates audit record with correct fields
    // -------------------------------------------------------------------------

    /** @test */
    public function permission_change_creates_audit_entry(): void
    {
        $actor      = $this->createTenantUser('actor-perm@example.com', 'secret123');
        $token      = $actor->createToken('api')->plainTextToken;
        $role       = Role::on('tenant')->create(['name' => 'editor-'.uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'edit-'.uniqid(), 'guard_name' => 'api']);

        $response = $this->postJson(
            "/api/iam/roles/{$role->id}/permissions/{$permission->id}",
            [],
            $this->authHeaders($token)
        );

        $response->assertStatus(200);

        $entry = AuditEntry::on('tenant')
            ->where('event', 'iam.permission_assigned')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($entry, 'AuditEntry for iam.permission_assigned must exist');
    }

    /** @test */
    public function permission_change_audit_has_correct_actor(): void
    {
        $actor      = $this->createTenantUser('perm-actor@example.com', 'secret123');
        $token      = $actor->createToken('api')->plainTextToken;
        $role       = Role::on('tenant')->create(['name' => 'mgr-'.uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'manage-'.uniqid(), 'guard_name' => 'api']);

        $this->postJson(
            "/api/iam/roles/{$role->id}/permissions/{$permission->id}",
            [],
            $this->authHeaders($token)
        );

        $entry = AuditEntry::on('tenant')
            ->where('event', 'iam.permission_assigned')
            ->latest('created_at')
            ->first();

        $this->assertSame((string) $actor->id, $entry->actor_id);
    }

    /** @test */
    public function permission_change_audit_has_correct_subject(): void
    {
        $actor      = $this->createTenantUser('subj@example.com', 'secret123');
        $token      = $actor->createToken('api')->plainTextToken;
        $role       = Role::on('tenant')->create(['name' => 'subj-role-'.uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'subj-perm-'.uniqid(), 'guard_name' => 'api']);

        $this->postJson(
            "/api/iam/roles/{$role->id}/permissions/{$permission->id}",
            [],
            $this->authHeaders($token)
        );

        $entry = AuditEntry::on('tenant')
            ->where('event', 'iam.permission_assigned')
            ->latest('created_at')
            ->first();

        $this->assertSame(Role::class, $entry->subject_type);
        $this->assertSame((string) $role->id, $entry->subject_id);
    }

    /** @test */
    public function permission_change_audit_captures_before_and_after(): void
    {
        $actor      = $this->createTenantUser('ba@example.com', 'secret123');
        $token      = $actor->createToken('api')->plainTextToken;
        $role       = Role::on('tenant')->create(['name' => 'ba-role-'.uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'ba-perm-'.uniqid(), 'guard_name' => 'api']);

        $this->postJson(
            "/api/iam/roles/{$role->id}/permissions/{$permission->id}",
            [],
            $this->authHeaders($token)
        );

        $entry = AuditEntry::on('tenant')
            ->where('event', 'iam.permission_assigned')
            ->latest('created_at')
            ->first();

        $this->assertIsArray($entry->before);
        $this->assertIsArray($entry->after);
        // Before: no permissions; After: the new permission is present.
        $this->assertEmpty($entry->before['permissions']);
        $this->assertContains($permission->name, $entry->after['permissions']);
    }

    /** @test */
    public function permission_change_audit_has_correct_tenant(): void
    {
        $actor      = $this->createTenantUser('perm-tenant@example.com', 'secret123');
        $token      = $actor->createToken('api')->plainTextToken;
        $role       = Role::on('tenant')->create(['name' => 'pt-role-'.uniqid(), 'guard_name' => 'api']);
        $permission = Permission::on('tenant')->create(['name' => 'pt-perm-'.uniqid(), 'guard_name' => 'api']);

        $this->postJson(
            "/api/iam/roles/{$role->id}/permissions/{$permission->id}",
            [],
            $this->authHeaders($token)
        );

        $entry = AuditEntry::on('tenant')
            ->where('event', 'iam.permission_assigned')
            ->latest('created_at')
            ->first();

        $this->assertSame('test-tenant', $entry->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // VP15-16 — Isolation: audit records in tenant schema
    // -------------------------------------------------------------------------

    /** @test */
    public function audit_records_are_written_to_tenant_schema(): void
    {
        $user = $this->createTenantUser('iso@example.com', 'secret123');

        $this->postJson('/api/auth/login', [
            'email'    => 'iso@example.com',
            'password' => 'secret123',
        ], $this->tenantHeaders());

        $count = DB::connection('tenant')
            ->table('audit_entries')
            ->where('event', 'auth.login')
            ->count();

        $this->assertGreaterThan(0, $count);
    }

    /** @test */
    public function audit_migration_is_in_per_tenant_path_not_shared(): void
    {
        // Structural verification: migration file location confirms per-tenant isolation.
        $this->assertFileExists(
            database_path('migrations/audit/2026_09_25_000001_create_audit_entries_table.php')
        );
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_25_000001_create_audit_entries_table.php')
        );
    }

    // -------------------------------------------------------------------------
    // VP17 — Immutability of integration-created AuditEntry
    // -------------------------------------------------------------------------

    /** @test */
    public function integration_created_audit_entry_is_immutable(): void
    {
        $user = $this->createTenantUser('immut@example.com', 'secret123');

        $this->postJson('/api/auth/login', [
            'email'    => 'immut@example.com',
            'password' => 'secret123',
        ], $this->tenantHeaders());

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.login')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($entry);

        $this->expectException(LogicException::class);
        $entry->update(['event' => 'tampered']);
    }

    // -------------------------------------------------------------------------
    // Cross-tenant verification (§11)
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_slug_in_audit_comes_from_resolved_context_not_caller(): void
    {
        // The resolved TenantContext for X-Tenant: test-tenant has slug = 'test-tenant'.
        // The AuditEntry must reflect this resolved slug — not any arbitrary value.
        $user = $this->createTenantUser('cross@example.com', 'secret123');

        $this->postJson('/api/auth/login', [
            'email'    => 'cross@example.com',
            'password' => 'secret123',
        ], $this->tenantHeaders());

        $entry = AuditEntry::on('tenant')
            ->where('event', 'auth.login')
            ->latest('created_at')
            ->first();

        // The tenant_slug must match the resolved context slug — 'test-tenant'.
        // It cannot be overridden by any caller-supplied value.
        $this->assertSame('test-tenant', $entry->tenant_slug);

        // Verify the AuditEntry is on the tenant connection (not shared).
        $this->assertSame('tenant', $entry->getConnectionName());
    }
}
