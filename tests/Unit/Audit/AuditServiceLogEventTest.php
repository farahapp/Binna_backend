<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\AuditEntry;
use App\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Unit tests for AuditService::logEvent().
 *
 * Verifies TASK-001C Unit 2 — logEvent() Capability.
 *
 * Verification points (from prompt §7):
 *  1. logEvent() creates an AuditEntry.
 *  2. Event value is persisted correctly.
 *  3. Actor identity is persisted correctly.
 *  4. tenant_slug is persisted correctly.
 *  5. Subject information is persisted when provided.
 *  6. Before/after context is persisted when provided.
 *  7. The record is written to the tenant schema.
 *  8. The created AuditEntry remains immutable.
 *  9. A valid minimal context (tenant_slug only) is accepted.
 * 10. Missing tenant_slug is rejected.
 * 11. Null actor is accepted (system/anonymous events — actor_id nullable in schema).
 */
class AuditServiceLogEventTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AuditService::class);
    }

    // -------------------------------------------------------------------------
    // VP1 — logEvent() creates an AuditEntry
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_creates_an_audit_entry(): void
    {
        $entry = $this->service->logEvent(
            'test.created',
            '00000000-0000-4000-8000-000000000001',
            ['tenant_slug' => 'test-tenant']
        );

        $this->assertInstanceOf(AuditEntry::class, $entry);
        $this->assertNotNull($entry->id);
    }

    // -------------------------------------------------------------------------
    // VP2 — Event value is persisted correctly
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_persists_event_name(): void
    {
        $entry = $this->service->logEvent(
            'auth.login',
            null,
            ['tenant_slug' => 'test-tenant']
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertSame('auth.login', $found->event);
    }

    // -------------------------------------------------------------------------
    // VP3 — Actor identity is persisted correctly
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_persists_actor_id(): void
    {
        $actorId = '00000000-0000-4000-8000-000000000042';

        $entry = $this->service->logEvent(
            'iam.role_assigned',
            $actorId,
            ['tenant_slug' => 'test-tenant']
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertSame($actorId, $found->actor_id);
    }

    // -------------------------------------------------------------------------
    // VP4 — tenant_slug is persisted correctly
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_persists_tenant_slug(): void
    {
        $entry = $this->service->logEvent(
            'test.event',
            null,
            ['tenant_slug' => 'acme-corp']
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertSame('acme-corp', $found->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // VP5 — Subject information is persisted when provided
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_persists_subject_when_provided(): void
    {
        $entry = $this->service->logEvent(
            'iam.permission_changed',
            '00000000-0000-4000-8000-000000000001',
            [
                'tenant_slug'  => 'test-tenant',
                'subject_type' => 'App\\IAM\\User',
                'subject_id'   => '00000000-0000-4000-8000-000000000099',
            ]
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertSame('App\\IAM\\User', $found->subject_type);
        $this->assertSame('00000000-0000-4000-8000-000000000099', $found->subject_id);
    }

    // -------------------------------------------------------------------------
    // VP6 — Before/after context is persisted when provided
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_persists_before_after_when_provided(): void
    {
        $before = ['status' => 'pending'];
        $after  = ['status' => 'active'];

        $entry = $this->service->logEvent(
            'foundation.tenant_status_changed',
            null,
            [
                'tenant_slug' => 'test-tenant',
                'before'      => $before,
                'after'       => $after,
            ]
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertIsArray($found->before);
        $this->assertIsArray($found->after);
        $this->assertSame('pending', $found->before['status']);
        $this->assertSame('active', $found->after['status']);
    }

    // -------------------------------------------------------------------------
    // VP7 — Record is written to the tenant schema
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_writes_to_tenant_connection(): void
    {
        $entry = $this->service->logEvent(
            'test.tenant_write',
            null,
            ['tenant_slug' => 'test-tenant']
        );

        $count = \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('audit_entries')
            ->where('id', $entry->id)
            ->count();

        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // VP8 — Created AuditEntry remains immutable
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_result_is_immutable(): void
    {
        $entry = $this->service->logEvent(
            'test.immutable',
            null,
            ['tenant_slug' => 'test-tenant']
        );

        $this->expectException(LogicException::class);
        $entry->update(['event' => 'tampered']);
    }

    // -------------------------------------------------------------------------
    // VP9 — Minimal context (tenant_slug only) is accepted
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_accepts_minimal_context(): void
    {
        $entry = $this->service->logEvent(
            'test.minimal',
            null,
            ['tenant_slug' => 'test-tenant']
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertNotNull($found);
        $this->assertNull($found->subject_type);
        $this->assertNull($found->subject_id);
        $this->assertNull($found->before);
        $this->assertNull($found->after);
        $this->assertNull($found->actor_id);
    }

    // -------------------------------------------------------------------------
    // VP10 — Missing tenant_slug is rejected
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_rejects_missing_tenant_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant_slug');

        $this->service->logEvent('test.event', null, []);
    }

    // -------------------------------------------------------------------------
    // VP11 — Null actor is accepted
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_accepts_null_actor(): void
    {
        $entry = $this->service->logEvent(
            'system.event',
            null,
            ['tenant_slug' => 'test-tenant']
        );

        $found = AuditEntry::on('tenant')->find($entry->id);
        $this->assertNull($found->actor_id);
    }

    // -------------------------------------------------------------------------
    // Cross-tenant structural verification (VP — Tenant Isolation)
    // -------------------------------------------------------------------------

    /** @test */
    public function log_event_uses_tenant_connection_not_shared(): void
    {
        // Structural verification: AuditService uses AuditEntry which is bound
        // to the 'tenant' connection (not 'mysql' shared connection).
        // This confirms logEvent() does not create its own tenant-resolution mechanism.
        $entry = $this->service->logEvent(
            'test.cross_tenant_check',
            null,
            ['tenant_slug' => 'tenant-a']
        );

        // The entry must be on the 'tenant' connection.
        $this->assertSame('tenant', $entry->getConnectionName());

        // The entry must NOT be on the shared 'mysql' connection.
        // (In test env both point to binaa2_testing — verified structurally via connection name.)
        $this->assertNotSame('mysql', $entry->getConnectionName());
    }
}
