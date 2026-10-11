<?php
declare(strict_types=1);
namespace App\HR\Events;
use App\HR\LeaveRequest;
class LeaveRequestApproved
{
    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly string $tenantSlug,
    ) {}
}
