<?php

declare(strict_types=1);

namespace Tests\Unit\Approval;

use App\Approval\ApprovalRequest;
use App\Approval\ApprovalStatus;
use App\Approval\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalStateMachineTest — TASK-002A-2
 *
 * Verifies the state machine defined by the architecture:
 *
 *   Valid:
 *     PENDING → APPROVED
 *     PENDING → REJECTED
 *     PENDING → CANCELLED
 *
 *   Invalid (must throw InvalidStateTransitionException):
 *     APPROVED → anything
 *     REJECTED → anything
 *     CANCELLED → anything
 *     PENDING → PENDING (self-transition)
 *
 * F-13 / SC-APPROVAL-1 / SC-APPROVAL-2 remain UNRESOLVED — not touched here.
 */
class ApprovalStateMachineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Valid transitions
    // -------------------------------------------------------------------------

    /** @test */
    public function pending_can_transition_to_approved(): void
    {
        $request = $this->pendingRequest();

        $request->transitionTo(ApprovalStatus::APPROVED);

        $this->assertSame(ApprovalStatus::APPROVED, $request->status);
    }

    /** @test */
    public function pending_can_transition_to_rejected(): void
    {
        $request = $this->pendingRequest();

        $request->transitionTo(ApprovalStatus::REJECTED);

        $this->assertSame(ApprovalStatus::REJECTED, $request->status);
    }

    /** @test */
    public function pending_can_transition_to_cancelled(): void
    {
        $request = $this->pendingRequest();

        $request->transitionTo(ApprovalStatus::CANCELLED);

        $this->assertSame(ApprovalStatus::CANCELLED, $request->status);
    }

    // -------------------------------------------------------------------------
    // Invalid: terminal states cannot transition
    // -------------------------------------------------------------------------

    /** @test */
    public function approved_cannot_transition_to_any_state(): void
    {
        foreach (ApprovalStatus::cases() as $target) {
            $request = $this->requestWithStatus(ApprovalStatus::APPROVED);

            $this->expectException(InvalidStateTransitionException::class);
            $request->transitionTo($target);
        }
    }

    /** @test */
    public function approved_cannot_transition_to_pending(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::APPROVED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::PENDING);
    }

    /** @test */
    public function approved_cannot_transition_to_rejected(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::APPROVED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::REJECTED);
    }

    /** @test */
    public function approved_cannot_transition_to_cancelled(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::APPROVED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::CANCELLED);
    }

    /** @test */
    public function rejected_cannot_transition_to_pending(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::REJECTED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::PENDING);
    }

    /** @test */
    public function rejected_cannot_transition_to_approved(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::REJECTED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::APPROVED);
    }

    /** @test */
    public function rejected_cannot_transition_to_cancelled(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::REJECTED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::CANCELLED);
    }

    /** @test */
    public function cancelled_cannot_transition_to_pending(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::CANCELLED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::PENDING);
    }

    /** @test */
    public function cancelled_cannot_transition_to_approved(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::CANCELLED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::APPROVED);
    }

    /** @test */
    public function cancelled_cannot_transition_to_rejected(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::CANCELLED);

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::REJECTED);
    }

    // -------------------------------------------------------------------------
    // Invalid: self-transition
    // -------------------------------------------------------------------------

    /** @test */
    public function pending_cannot_transition_to_pending(): void
    {
        $request = $this->pendingRequest();

        $this->expectException(InvalidStateTransitionException::class);
        $request->transitionTo(ApprovalStatus::PENDING);
    }

    // -------------------------------------------------------------------------
    // Exception message verification
    // -------------------------------------------------------------------------

    /** @test */
    public function exception_message_identifies_terminal_state(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::APPROVED);

        try {
            $request->transitionTo(ApprovalStatus::REJECTED);
            $this->fail('Expected InvalidStateTransitionException was not thrown.');
        } catch (InvalidStateTransitionException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }
    }

    /** @test */
    public function exception_message_identifies_self_transition(): void
    {
        $request = $this->pendingRequest();

        try {
            $request->transitionTo(ApprovalStatus::PENDING);
            $this->fail('Expected InvalidStateTransitionException was not thrown.');
        } catch (InvalidStateTransitionException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // State integrity after failed transition
    // -------------------------------------------------------------------------

    /** @test */
    public function status_is_unchanged_after_failed_transition(): void
    {
        $request = $this->requestWithStatus(ApprovalStatus::APPROVED);

        try {
            $request->transitionTo(ApprovalStatus::REJECTED);
        } catch (InvalidStateTransitionException) {
            // expected
        }

        $this->assertSame(ApprovalStatus::APPROVED, $request->status);
    }

    /** @test */
    public function status_is_unchanged_after_self_transition_attempt(): void
    {
        $request = $this->pendingRequest();

        try {
            $request->transitionTo(ApprovalStatus::PENDING);
        } catch (InvalidStateTransitionException) {
            // expected
        }

        $this->assertSame(ApprovalStatus::PENDING, $request->status);
    }
}
