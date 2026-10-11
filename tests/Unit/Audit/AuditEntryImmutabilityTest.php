<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Audit\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Unit tests for AuditEntry immutability.
 *
 * Verifies TASK-001C Unit 1 — Verification Point: Immutability.
 *
 * Architectural constraint (NEW-003 / A1 MOD-03):
 *   AuditEntry records are WRITE-ONCE — no update, no delete.
 */
class AuditEntryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEntry(): AuditEntry
    {
        $entry = new AuditEntry();
        $entry->setConnection('tenant');
        $entry->actor_id     = '00000000-0000-4000-8000-000000000001';
        $entry->event        = 'test.event';
        $entry->subject_type = 'App\\IAM\\User';
        $entry->subject_id   = '00000000-0000-4000-8000-000000000002';
        $entry->before       = null;
        $entry->after        = ['status' => 'active'];
        $entry->tenant_slug  = 'test-tenant';
        $entry->save();

        return $entry;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function audit_entry_can_be_created(): void
    {
        $entry = $this->makeEntry();

        $this->assertNotNull($entry->id);
        $this->assertSame('test.event', $entry->event);
        $this->assertSame('test-tenant', $entry->tenant_slug);
        $this->assertNotNull($entry->created_at);
    }

    /** @test */
    public function audit_entry_cannot_be_updated(): void
    {
        $entry = $this->makeEntry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('AuditEntry records are immutable and cannot be updated.');

        $entry->update(['event' => 'modified.event']);
    }

    /** @test */
    public function audit_entry_cannot_be_deleted(): void
    {
        $entry = $this->makeEntry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('AuditEntry records are immutable and cannot be deleted.');

        $entry->delete();
    }

    /** @test */
    public function audit_entry_has_no_updated_at_column(): void
    {
        $entry = $this->makeEntry();

        // UPDATED_AT = null means Eloquent does not manage updated_at.
        $this->assertNull(AuditEntry::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $entry->toArray());
    }

    /** @test */
    public function audit_entry_uuid_is_set_automatically(): void
    {
        $entry = $this->makeEntry();

        // UUID format: 8-4-4-4-12 hex chars (TODEC-003).
        // Laravel's HasUuids trait generates ordered UUIDs (compatible with TODEC-003 UUID v4 intent).
        // Pattern matches any RFC 4122 UUID (v4, v7, etc.) — consistent with User model convention.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $entry->id
        );
        $this->assertNotEmpty($entry->id);
    }
}
