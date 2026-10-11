<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Approval\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for ApprovalRequest tenant isolation and migration placement.
 *
 * Verifies TASK-002A — Approval Shared Service:
 *   - Migration: approval_requests table exists in tenant schema.
 *   - Isolation: migration is in the correct per-tenant path.
 *   - Model: ApprovalRequest uses the 'tenant' connection.
 */
class ApprovalTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function approval_requests_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('approval_requests'),
            'approval_requests table must exist in the tenant schema'
        );
    }

    /** @test */
    public function approval_requests_migration_is_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/approval/2026_09_26_000001_create_approval_requests_table.php'),
            'approval_requests migration must be in the per-tenant approval migration path'
        );

        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_26_000001_create_approval_requests_table.php'),
            'approval_requests migration must NOT be in the shared migration path'
        );
    }

    /** @test */
    public function approval_request_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new ApprovalRequest())->getConnectionName(),
            'ApprovalRequest must use the tenant connection'
        );
    }
}
