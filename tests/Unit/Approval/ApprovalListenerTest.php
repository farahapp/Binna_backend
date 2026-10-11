<?php

declare(strict_types=1);

namespace Tests\Unit\Approval;

use App\Approval\ApprovalRequest;
use App\Approval\ApprovalStatus;
use App\Approval\Events\ApprovalGranted;
use App\Approval\Events\ApprovalRejected;
use App\Approval\Listeners\RecordApprovalAuditEntry;
use App\Audit\AuditEntry;
use App\Audit\AuditService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalListenerTest — TASK-002A-4
 *
 * Unit tests for RecordApprovalAuditEntry listener.
 *
 * Verifies:
 *   1. ApprovalGranted event dispatches correct audit call.
 *   2. ApprovalRejected event dispatches correct audit call.
 *   3. Correct event names ('approval.granted', 'approval.rejected').
 *   4. Tenant slug is passed from the ApprovalRequest to AuditService.
 *   5. Actor ID is passed from the event to AuditService.
 *   6. Approval request identity (approvable_type, approvable_id, id) is in context.
 *   7. No direct AuditEntry write from listener (goes through AuditService).
 *
 * F-13 / SC-APPROVAL-1 / SC-APPROVAL-2 remain UNRESOLVED — not touched here.
 */
class ApprovalListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeApprovalRequest(
        string $id = 'req-uuid-001',
        string $tenantSlug = 'test-tenant',
        string $approvableType = 'App\\Procurement\\PurchaseOrder',
        string $approvableId = 'entity-uuid-001',
    ): ApprovalRequest {
        $m = new ApprovalRequest();
        $m->setAttribute('id', $id);
        $m->tenant_slug      = $tenantSlug;
        $m->approvable_type  = $approvableType;
        $m->approvable_id    = $approvableId;
        $m->status           = ApprovalStatus::APPROVED;
        return $m;
    }

    private function makeAuditServiceMock(): MockInterface&AuditService
    {
        /** @var MockInterface&AuditService $mock */
        return Mockery::mock(AuditService::class);
    }

    // -------------------------------------------------------------------------
    // ApprovalGranted — event name
    // -------------------------------------------------------------------------

    /** @test */
    public function granted_event_calls_log_event_with_approval_granted_name(): void
    {
        $request  = $this->makeApprovalRequest();
        $event    = new ApprovalGranted($request, 'actor-uuid-001');
        $captured = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->with('approval.granted', Mockery::any(), Mockery::any())
            ->andReturnUsing(function (string $eventName) use (&$captured) {
                $captured = $eventName;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame('approval.granted', $captured);
    }

    /** @test */
    public function rejected_event_calls_log_event_with_approval_rejected_name(): void
    {
        $request  = $this->makeApprovalRequest();
        $request->status = ApprovalStatus::REJECTED;
        $event    = new ApprovalRejected($request, 'actor-uuid-001');
        $captured = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->with('approval.rejected', Mockery::any(), Mockery::any())
            ->andReturnUsing(function (string $eventName) use (&$captured) {
                $captured = $eventName;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame('approval.rejected', $captured);
    }

    // -------------------------------------------------------------------------
    // Actor ID propagation
    // -------------------------------------------------------------------------

    /** @test */
    public function granted_event_passes_actor_id_to_audit_service(): void
    {
        $actorId  = 'actor-uuid-999';
        $request  = $this->makeApprovalRequest();
        $event    = new ApprovalGranted($request, $actorId);
        $captured = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->with(Mockery::any(), $actorId, Mockery::any())
            ->andReturnUsing(function ($e, string $actor) use (&$captured) {
                $captured = $actor;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($actorId, $captured);
    }

    /** @test */
    public function rejected_event_passes_actor_id_to_audit_service(): void
    {
        $actorId  = 'actor-uuid-888';
        $request  = $this->makeApprovalRequest();
        $request->status = ApprovalStatus::REJECTED;
        $event    = new ApprovalRejected($request, $actorId);
        $captured = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->with(Mockery::any(), $actorId, Mockery::any())
            ->andReturnUsing(function ($e, string $actor) use (&$captured) {
                $captured = $actor;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($actorId, $captured);
    }

    /** @test */
    public function granted_event_passes_null_actor_when_not_provided(): void
    {
        $request  = $this->makeApprovalRequest();
        $event    = new ApprovalGranted($request, null);
        $captured = 'NOT_CALLED';

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturnUsing(function ($e, $actor) use (&$captured) {
                $captured = $actor;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertNull($captured);
    }

    // -------------------------------------------------------------------------
    // Tenant slug propagation
    // -------------------------------------------------------------------------

    /** @test */
    public function granted_event_passes_tenant_slug_in_context(): void
    {
        $tenantSlug = 'acme-corp';
        $request    = $this->makeApprovalRequest(tenantSlug: $tenantSlug);
        $event      = new ApprovalGranted($request, null);
        $captured   = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->andReturnUsing(function ($e, $a, array $ctx) use (&$captured) {
                $captured = $ctx['tenant_slug'] ?? null;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($tenantSlug, $captured);
    }

    /** @test */
    public function rejected_event_passes_tenant_slug_in_context(): void
    {
        $tenantSlug = 'beta-tenant';
        $request    = $this->makeApprovalRequest(tenantSlug: $tenantSlug);
        $request->status = ApprovalStatus::REJECTED;
        $event    = new ApprovalRejected($request, null);
        $captured = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->andReturnUsing(function ($e, $a, array $ctx) use (&$captured) {
                $captured = $ctx['tenant_slug'] ?? null;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($tenantSlug, $captured);
    }

    // -------------------------------------------------------------------------
    // Approval request identity in context
    // -------------------------------------------------------------------------

    /** @test */
    public function granted_event_includes_approval_request_id_in_context(): void
    {
        $requestId = 'req-uuid-777';
        $request   = $this->makeApprovalRequest(id: $requestId);
        $event     = new ApprovalGranted($request, null);
        $captured  = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->andReturnUsing(function ($e, $a, array $ctx) use (&$captured) {
                $captured = $ctx['after']['approval_request_id'] ?? null;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($requestId, $captured);
    }

    /** @test */
    public function granted_event_includes_approvable_type_in_context(): void
    {
        $approvableType = 'App\\Procurement\\PurchaseOrder';
        $request        = $this->makeApprovalRequest(approvableType: $approvableType);
        $event          = new ApprovalGranted($request, null);
        $captured       = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->andReturnUsing(function ($e, $a, array $ctx) use (&$captured) {
                $captured = $ctx['subject_type'] ?? null;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($approvableType, $captured);
    }

    /** @test */
    public function granted_event_includes_approvable_id_in_context(): void
    {
        $approvableId = 'entity-uuid-555';
        $request      = $this->makeApprovalRequest(approvableId: $approvableId);
        $event        = new ApprovalGranted($request, null);
        $captured     = null;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->andReturnUsing(function ($e, $a, array $ctx) use (&$captured) {
                $captured = $ctx['subject_id'] ?? null;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame($approvableId, $captured);
    }

    // -------------------------------------------------------------------------
    // No direct AuditEntry write — goes through AuditService
    // -------------------------------------------------------------------------

    /** @test */
    public function listener_calls_audit_service_exactly_once_per_event(): void
    {
        $request  = $this->makeApprovalRequest();
        $event    = new ApprovalGranted($request, null);
        $callCount = 0;

        $auditService = $this->makeAuditServiceMock();
        $auditService->shouldReceive('logEvent')
            ->once()
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return new AuditEntry();
            });

        $listener = new RecordApprovalAuditEntry($auditService);
        $listener->handle($event);

        $this->assertSame(1, $callCount);
    }

    // -------------------------------------------------------------------------
    // Event structure verification
    // -------------------------------------------------------------------------

    /** @test */
    public function approval_granted_event_carries_approval_request_and_actor(): void
    {
        $request = $this->makeApprovalRequest();
        $actorId = 'actor-uuid-123';

        $event = new ApprovalGranted($request, $actorId);

        $this->assertSame($request, $event->approvalRequest);
        $this->assertSame($actorId, $event->actorId);
    }

    /** @test */
    public function approval_rejected_event_carries_approval_request_and_actor(): void
    {
        $request = $this->makeApprovalRequest();
        $actorId = 'actor-uuid-456';

        $event = new ApprovalRejected($request, $actorId);

        $this->assertSame($request, $event->approvalRequest);
        $this->assertSame($actorId, $event->actorId);
    }

    /** @test */
    public function approval_granted_event_actor_can_be_null(): void
    {
        $request = $this->makeApprovalRequest();
        $event   = new ApprovalGranted($request, null);

        $this->assertNull($event->actorId);
    }
}
