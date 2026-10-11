<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Approval\ApprovalRequest;
use App\Approval\ApprovalService;
use App\Approval\ApprovalStatus;
use App\Approval\Events\ApprovalGranted;
use App\Approval\Events\ApprovalRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\TestCase;

/**
 * Feature tests for ApprovalService.
 *
 * Covers TASK-002A — Approval Shared Service.
 */
class ApprovalServiceTest extends TestCase
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

    private function makeRequest(array $overrides = []): ApprovalRequest
    {
        return $this->service->requestApproval(
            approvableType: $overrides['approvable_type'] ?? 'App\\Procurement\\PurchaseOrder',
            approvableId:   $overrides['approvable_id']   ?? '00000000-0000-7000-8000-000000000001',
            requestedBy:    $overrides['requested_by']    ?? '00000000-0000-7000-8000-000000000002',
            tenantSlug:     $overrides['tenant_slug']     ?? 'test-tenant',
            notes:          $overrides['notes']           ?? null,
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function approval_request_can_be_created_in_pending_state(): void
    {
        $request = $this->makeRequest();

        $this->assertNotNull($request->id);
        $this->assertSame(ApprovalStatus::PENDING, $request->status);
    }

    /** @test */
    public function approval_request_stored_in_tenant_connection(): void
    {
        $request = $this->makeRequest();

        $this->assertSame('tenant', $request->getConnectionName());

        $found = ApprovalRequest::on('tenant')->find($request->id);
        $this->assertNotNull($found);
    }

    /** @test */
    public function approval_request_tenant_slug_is_stored(): void
    {
        $request = $this->makeRequest(['tenant_slug' => 'acme-corp']);

        $this->assertSame('acme-corp', $request->tenant_slug);

        $found = ApprovalRequest::on('tenant')->find($request->id);
        $this->assertSame('acme-corp', $found->tenant_slug);
    }

    /** @test */
    public function get_approval_status_returns_correct_request(): void
    {
        $request = $this->makeRequest();

        $found = $this->service->getApprovalStatus($request->id);

        $this->assertSame($request->id, $found->id);
        $this->assertSame(ApprovalStatus::PENDING, $found->status);
    }

    /** @test */
    public function get_approval_status_throws_for_unknown_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->getApprovalStatus('00000000-0000-7000-8000-nonexistent0');
    }

    /** @test */
    public function pending_request_can_be_cancelled(): void
    {
        $request = $this->makeRequest();

        $cancelled = $this->service->cancelApprovalRequest($request->id);

        $this->assertSame(ApprovalStatus::CANCELLED, $cancelled->status);

        $found = ApprovalRequest::on('tenant')->find($request->id);
        $this->assertSame(ApprovalStatus::CANCELLED, $found->status);
    }

    /** @test */
    public function non_pending_request_cannot_be_cancelled(): void
    {
        $request = $this->makeRequest();
        $this->service->approve($request->id);

        $this->expectException(LogicException::class);

        $this->service->cancelApprovalRequest($request->id);
    }

    /** @test */
    public function pending_request_can_be_approved(): void
    {
        $request = $this->makeRequest();

        $approved = $this->service->approve($request->id);

        $this->assertSame(ApprovalStatus::APPROVED, $approved->status);

        $found = ApprovalRequest::on('tenant')->find($request->id);
        $this->assertSame(ApprovalStatus::APPROVED, $found->status);
    }

    /** @test */
    public function pending_request_can_be_rejected(): void
    {
        $request = $this->makeRequest();

        $rejected = $this->service->reject($request->id);

        $this->assertSame(ApprovalStatus::REJECTED, $rejected->status);

        $found = ApprovalRequest::on('tenant')->find($request->id);
        $this->assertSame(ApprovalStatus::REJECTED, $found->status);
    }

    /** @test */
    public function non_pending_request_cannot_be_approved(): void
    {
        $request = $this->makeRequest();
        $this->service->reject($request->id);

        $this->expectException(LogicException::class);

        $this->service->approve($request->id);
    }

    /** @test */
    public function non_pending_request_cannot_be_rejected(): void
    {
        $request = $this->makeRequest();
        $this->service->approve($request->id);

        $this->expectException(LogicException::class);

        $this->service->reject($request->id);
    }

    /** @test */
    public function approval_granted_event_is_dispatched_on_approve(): void
    {
        Event::fake([ApprovalGranted::class]);

        $request = $this->makeRequest();
        $actorId = '00000000-0000-7000-8000-000000000099';

        $this->service->approve($request->id, $actorId);

        Event::assertDispatched(ApprovalGranted::class, function (ApprovalGranted $event) use ($request, $actorId) {
            return $event->approvalRequest->id === $request->id
                && $event->actorId === $actorId;
        });
    }

    /** @test */
    public function approval_rejected_event_is_dispatched_on_reject(): void
    {
        Event::fake([ApprovalRejected::class]);

        $request = $this->makeRequest();
        $actorId = '00000000-0000-7000-8000-000000000099';

        $this->service->reject($request->id, $actorId);

        Event::assertDispatched(ApprovalRejected::class, function (ApprovalRejected $event) use ($request, $actorId) {
            return $event->approvalRequest->id === $request->id
                && $event->actorId === $actorId;
        });
    }

    /** @test */
    public function approval_request_requires_non_empty_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->requestApproval(
            approvableType: '',
            approvableId:   '00000000-0000-7000-8000-000000000001',
            requestedBy:    '00000000-0000-7000-8000-000000000002',
            tenantSlug:     'test-tenant',
        );
    }
}
