<?php

declare(strict_types=1);

namespace App\Registration;

/**
 * Maps legacy single status to additive verification/approval dimensions.
 * Does not replace TenantRegistrationRequest::$status.
 */
final class RegistrationLifecycle
{
    /**
     * @return array{verification_status: string, approval_status: string}
     */
    public static function fromRegistrationStatus(string $status): array
    {
        return match ($status) {
            'draft' => ['verification_status' => 'not_started', 'approval_status' => 'pending'],
            'submitted' => ['verification_status' => 'pending', 'approval_status' => 'pending'],
            'under_review' => ['verification_status' => 'in_progress', 'approval_status' => 'pending'],
            'needs_information' => ['verification_status' => 'failed', 'approval_status' => 'pending'],
            'approved_pending_provisioning', 'provisioned' => [
                'verification_status' => 'verified',
                'approval_status' => 'approved',
            ],
            'rejected' => ['verification_status' => 'failed', 'approval_status' => 'rejected'],
            default => ['verification_status' => 'not_started', 'approval_status' => 'pending'],
        };
    }
}
