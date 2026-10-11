<?php

declare(strict_types=1);

namespace App\Approval\Listeners;

use App\Approval\Events\ApprovalGranted;
use App\Approval\Events\ApprovalRejected;
use App\Audit\AuditService;

/**
 * RecordApprovalAuditEntry — Listens to ApprovalGranted and ApprovalRejected
 * events and writes an immutable AuditEntry via AuditService.
 *
 * This listener is the bridge between the Approval domain and the Audit domain.
 * ApprovalService dispatches events; this listener calls AuditService::logEvent().
 * ApprovalService does NOT call AuditService directly (MOD-03 boundary).
 */
class RecordApprovalAuditEntry
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Handle both ApprovalGranted and ApprovalRejected events.
     */
    public function handle(ApprovalGranted|ApprovalRejected $event): void
    {
        if ($event instanceof ApprovalGranted) {
            $this->auditService->logEvent(
                'approval.granted',
                $event->actorId,
                [
                    'tenant_slug'  => $event->approvalRequest->tenant_slug,
                    'subject_type' => $event->approvalRequest->approvable_type,
                    'subject_id'   => $event->approvalRequest->approvable_id,
                    'after'        => [
                        'status'              => 'approved',
                        'approval_request_id' => $event->approvalRequest->id,
                    ],
                ]
            );

            return;
        }

        // ApprovalRejected
        $this->auditService->logEvent(
            'approval.rejected',
            $event->actorId,
            [
                'tenant_slug'  => $event->approvalRequest->tenant_slug,
                'subject_type' => $event->approvalRequest->approvable_type,
                'subject_id'   => $event->approvalRequest->approvable_id,
                'after'        => [
                    'status'              => 'rejected',
                    'approval_request_id' => $event->approvalRequest->id,
                ],
            ]
        );
    }
}
