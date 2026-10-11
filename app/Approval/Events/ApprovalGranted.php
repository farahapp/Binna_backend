<?php

declare(strict_types=1);

namespace App\Approval\Events;

use App\Approval\ApprovalRequest;

class ApprovalGranted
{
    public function __construct(
        public readonly ApprovalRequest $approvalRequest,
        public readonly string|null $actorId,
    ) {}
}
