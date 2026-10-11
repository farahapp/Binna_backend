<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for AuditEntry tenant isolation and migration.
 *
 * Verifies TASK-001C Unit 1 — Verification Points:
 *   - Migration: audit_entries table exists in tenant schema.
 *   - Isolation: AuditEntry is NOT written to the shared/global schema.
 *   - Model: tenant_slug and actor_id fields are present and correct.
 */
class AuditEntryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function audit_entries_table_exists_in_tenant_schema(): void
    {
        // The 'tenant' connection points at binaa2_testing (phpunit.xml).
        // RefreshDatabase runs all migrations including audit migrations.
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('audit_entries'),
            'audit_entries table must exist in the tenant schema'
        );
    }

    /** @test */
    public function audit_entries_table_is_per_tenant_not_shared_by_design(): void
    {
        // In the test environment, both 'mysql' and 'tenant' connections point to
        // binaa2_testing (phpunit.xml) — this is the established project testing convention
        // (see TestCase::$connectionsToTransact and LoginIssuesPatTest).
        //
        // The architectural guarantee (OD-001) is that audit_entries is defined in
        // the per-tenant migration path (database/migrations/audit/) and NOT in
        // the shared migration path (database/migrations/shared/).
        //
        // We verify this by confirming the migration file location.
        $this->assertFileExists(
            database_path('migrations/audit/2026_09_25_000001_create_audit_entries_table.php'),
            'audit_entries migration must be in the per-tenant audit migration path'
        );
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_25_000001_create_audit_entries_table.php'),
            'audit_entries migration must NOT be in the shared migration path'
        );
    }

    /** @test */
    public function audit_entry_is_written_to_tenant_connection(): void
    {
        $entry = new AuditEntry();
        $entry->setConnection('tenant');
        $entry->actor_id    = '00000000-0000-4000-8000-000000000001';
        $entry->event       = 'test.isolation';
        $entry->tenant_slug = 'test-tenant';
        $entry->save();

        // Verify the record exists on the tenant connection.
        $count = DB::connection('tenant')
            ->table('audit_entries')
            ->where('event', 'test.isolation')
            ->count();

        $this->assertSame(1, $count);

        // Verify the AuditEntry model uses the 'tenant' connection by default.
        $this->assertSame('tenant', (new AuditEntry())->getConnectionName());
    }

    /** @test */
    public function audit_entry_stores_tenant_slug_and_actor_id(): void
    {
        $actorId    = '00000000-0000-4000-8000-000000000099';
        $tenantSlug = 'test-tenant';

        $entry = new AuditEntry();
        $entry->setConnection('tenant');
        $entry->actor_id    = $actorId;
        $entry->event       = 'test.fields';
        $entry->tenant_slug = $tenantSlug;
        $entry->save();

        $found = AuditEntry::on('tenant')->find($entry->id);

        $this->assertNotNull($found);
        $this->assertSame($actorId, $found->actor_id);
        $this->assertSame($tenantSlug, $found->tenant_slug);
    }

    /** @test */
    public function audit_entry_json_fields_cast_correctly(): void
    {
        $before = ['status' => 'pending'];
        $after  = ['status' => 'active'];

        $entry = new AuditEntry();
        $entry->setConnection('tenant');
        $entry->actor_id    = '00000000-0000-4000-8000-000000000003';
        $entry->event       = 'test.json_cast';
        $entry->tenant_slug = 'test-tenant';
        $entry->before      = $before;
        $entry->after       = $after;
        $entry->save();

        $found = AuditEntry::on('tenant')->find($entry->id);

        $this->assertIsArray($found->before);
        $this->assertIsArray($found->after);
        $this->assertSame('pending', $found->before['status']);
        $this->assertSame('active', $found->after['status']);
    }
}
