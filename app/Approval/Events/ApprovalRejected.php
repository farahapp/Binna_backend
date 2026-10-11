<?php

declare(strict_types=1);

namespace App\Approval\Events;

use App\Approval\ApprovalRequest;

class ApprovalRejected
{
    public function __construct(
        public readonly ApprovalRequest $approvalRequest,
        public readonly string|null $actorId,
    ) {}
}
