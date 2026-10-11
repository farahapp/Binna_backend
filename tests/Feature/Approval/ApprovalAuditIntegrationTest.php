<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Approval\ApprovalService;
use App\Audit\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests verifying that Approval events produce AuditEntry records.
 *
 * Tests the full path: ApprovalService → Event → RecordApprovalAuditEntry → AuditService → AuditEntry.
 */
class ApprovalAuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ApprovalService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(string $tenantSlug = 'test-tenant'): \App\Approval\ApprovalRequest
    {
        return $this->service->requestApproval(
            approvableType: 'App\\Procurement\\PurchaseOrder',
            approvableId:   '00000000-0000-7000-8000-000000000001',
            requestedBy:    '00000000-0000-7000-8000-000000000002',
            tenantSlug:     $tenantSlug,
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function approval_granted_creates_audit_entry(): void
    {
        $request = $this->makeRequest();

        $this->service->approve($request->id, '00000000-0000-7000-8000-000000000099');

        $entry = AuditEntry::on('tenant')
            ->where('event', 'approval.granted')
            ->first();

        $this->assertNotNull($entry, 'AuditEntry with event=approval.granted must exist after approve()');
        $this->assertSame('approval.granted', $entry->event);
    }

    /** @test */
    public function approval_rejected_creates_audit_entry(): void
    {
        $request = $this->makeRequest();

        $this->service->reject($request->id, '00000000-0000-7000-8000-000000000099');

        $entry = AuditEntry::on('tenant')
            ->where('event', 'approval.rejected')
            ->first();

        $this->assertNotNull($entry, 'AuditEntry with event=approval.rejected must exist after reject()');
        $this->assertSame('approval.rejected', $entry->event);
    }

    /** @test */
    public function audit_entry_preserves_tenant_slug(): void
    {
        $tenantSlug = 'acme-corp';
        $request    = $this->makeRequest($tenantSlug);

        $this->service->approve($request->id);

        $entry = AuditEntry::on('tenant')
            ->where('event', 'approval.granted')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($tenantSlug, $entry->tenant_slug);
    }

    /** @test */
    public function audit_entry_preserves_actor_id(): void
    {
        $actorId = '00000000-0000-7000-8000-000000000099';
        $request = $this->makeRequest();

        $this->service->approve($request->id, $actorId);

        $entry = AuditEntry::on('tenant')
            ->where('event', 'approval.granted')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($actorId, $entry->actor_id);
    }
}
