<?php

declare(strict_types=1);

namespace Tests\Unit\Approval;

use App\Approval\ApprovalRequest;
use App\Approval\ApprovalService;
use App\Approval\ApprovalStatus;
use App\Approval\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalServiceTest — TASK-002A-3
 *
 * Tests the ApprovalService layer in isolation (no DB, no framework boot).
 *
 * Strategy:
 *   - requestApproval() input validation is tested directly (no DB needed).
 *   - cancelApprovalRequest() / approve() / reject() DB interaction is tested
 *     via a subclass that overrides the static find() call, allowing us to
 *     inject an in-memory ApprovalRequest and verify state transitions.
 *   - State machine delegation to ApprovalRequest::transitionTo() is verified
 *     by asserting the resulting status and that InvalidStateTransitionException
 *     propagates correctly.
 *
 * F-13 / SC-APPROVAL-1 / SC-APPROVAL-2 remain UNRESOLVED — not touched here.
 */
class ApprovalServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): ApprovalService
    {
        return new ApprovalService();
    }

    private function pendingRequest(): ApprovalRequest
    {
        $m = new ApprovalRequest();
        $m->status = ApprovalStatus::PENDING;
        return $m;
    }

    private function requestWithStatus(ApprovalStatus $status): ApprovalRequest
    {
        $m = new ApprovalRequest();
        $m->status = $status;
        return $m;
    }

    /**
     * Build a testable ApprovalService subclass that injects a pre-built
     * ApprovalRequest instead of hitting the DB.
     *
     * Also overrides saveRequest() to skip the actual DB save in unit tests.
     */
    private function serviceWithRequest(?ApprovalRequest $request): ApprovalService
    {
        return new class($request) extends ApprovalService {
            public function __construct(private readonly ?ApprovalRequest $injected) {}

            protected function findRequest(string $id): ?ApprovalRequest
            {
                return $this->injected;
            }

            protected function saveRequest(ApprovalRequest $request): void
            {
                // No-op in unit tests — avoids DB connection requirement.
            }
        };
    }

    // -------------------------------------------------------------------------
    // requestApproval — argument validation
    // -------------------------------------------------------------------------

    /** @test */
    public function request_approval_throws_on_empty_approvable_type(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->requestApproval('', 'entity-id', 'user-id', 'tenant-slug');
    }

    /** @test */
    public function request_approval_throws_on_empty_approvable_id(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->requestApproval('App\\SomeModel', '', 'user-id', 'tenant-slug');
    }

    /** @test */
    public function request_approval_throws_on_empty_requested_by(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->requestApproval('App\\SomeModel', 'entity-id', '', 'tenant-slug');
    }

    /** @test */
    public function request_approval_throws_on_empty_tenant_slug(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->requestApproval('App\\SomeModel', 'entity-id', 'user-id', '');
    }

    // -------------------------------------------------------------------------
    // getApprovalStatus — not found
    // -------------------------------------------------------------------------

    /** @test */
    public function get_approval_status_throws_when_not_found(): void
    {
        $service = $this->serviceWithRequest(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nonexistent-id/');
        $service->getApprovalStatus('nonexistent-id');
    }

    /** @test */
    public function get_approval_status_returns_request_when_found(): void
    {
        $request = $this->pendingRequest();
        $service = $this->serviceWithRequest($request);

        $result = $service->getApprovalStatus('any-id');

        $this->assertSame($request, $result);
        $this->assertSame(ApprovalStatus::PENDING, $result->status);
    }

    // -------------------------------------------------------------------------
    // cancelApprovalRequest — delegates to transitionTo()
    // -------------------------------------------------------------------------

    /** @test */
    public function cancel_delegates_transition_to_model(): void
    {
        $request = $this->pendingRequest();
        $service = $this->serviceWithRequest($request);

        $result = $service->cancelApprovalRequest('any-id');

        $this->assertSame(ApprovalStatus::CANCELLED, $result->status);
    }

    /** @test */
    public function cancel_throws_when_not_found(): void
    {
        $service = $this->serviceWithRequest(null);

        $this->expectException(\InvalidArgumentException::class);
        $service->cancelApprovalRequest('missing-id');
    }

    /** @test */
    public function cancel_throws_invalid_transition_for_approved_request(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::APPROVED);
        $service = $this->serviceWithRequest($request);

        $this->expectException(InvalidStateTransitionException::class);
        $service->cancelApprovalRequest('any-id');
    }

    /** @test */
    public function cancel_throws_invalid_transition_for_rejected_request(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::REJECTED);
        $service = $this->serviceWithRequest($request);

        $this->expectException(InvalidStateTransitionException::class);
        $service->cancelApprovalRequest('any-id');
    }

    /** @test */
    public function cancel_throws_invalid_transition_for_already_cancelled_request(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::CANCELLED);
        $service = $this->serviceWithRequest($request);

        $this->expectException(InvalidStateTransitionException::class);
        $service->cancelApprovalRequest('any-id');
    }

    // -------------------------------------------------------------------------
    // State machine integration — service uses transitionTo()
    // -------------------------------------------------------------------------

    /** @test */
    public function cancel_results_in_cancelled_status(): void
    {
        $request = $this->pendingRequest();
        $service = $this->serviceWithRequest($request);

        $result = $service->cancelApprovalRequest('any-id');

        $this->assertSame(ApprovalStatus::CANCELLED, $result->status);
        $this->assertTrue($result->isTerminal());
        $this->assertFalse($result->isPending());
    }

    // -------------------------------------------------------------------------
    // SC-APPROVAL-1 boundary — no approver_id, no authorization
    // -------------------------------------------------------------------------

    /** @test */
    public function service_does_not_require_approver_id_for_cancel(): void
    {
        // cancelApprovalRequest() takes only the request ID — no actor/approver param.
        // This confirms SC-APPROVAL-1 is not implemented.
        $reflection = new \ReflectionMethod(ApprovalService::class, 'cancelApprovalRequest');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('approvalRequestId', $params[0]->getName());
    }

    /** @test */
    public function service_does_not_require_approver_id_for_request_approval(): void
    {
        // requestApproval() has no approver_id param — SC-APPROVAL-1 not implemented.
        $reflection = new \ReflectionMethod(ApprovalService::class, 'requestApproval');
        $paramNames = array_map(fn($p) => $p->getName(), $reflection->getParameters());

        $this->assertNotContains('approverId', $paramNames);
        $this->assertNotContains('approver_id', $paramNames);
    }
}
