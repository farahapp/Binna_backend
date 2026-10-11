<?php

declare(strict_types=1);

namespace App\Subcontracts\Events;

/**
 * SubcontractCompleted — Domain event raised when a subcontract agreement is completed (TASK-009A).
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractCompleted
{
    public function __construct(
        public readonly string $subcontractAgreementId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
