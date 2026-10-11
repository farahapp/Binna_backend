<?php

declare(strict_types=1);

namespace App\Approval;

use LogicException;

/**
 * Thrown when an invalid state transition is attempted on an ApprovalRequest.
 *
 * Valid transitions (TASK-002A-2):
 *   PENDING → APPROVED
 *   PENDING → REJECTED
 *   PENDING → CANCELLED
 *
 * All other transitions are invalid.
 *
 * Extends LogicException: invalid state transitions are programming logic errors
 * (calling a transition that is architecturally disallowed from this state).
 */
class InvalidStateTransitionException extends LogicException
{
}
