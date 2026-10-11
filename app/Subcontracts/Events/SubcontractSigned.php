<?php

declare(strict_types=1);

namespace App\Subcontracts\Events;

/**
 * SubcontractSigned — Domain event raised when a subcontract agreement is signed (TASK-009A).
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractSigned
{
    public function __construct(
        public readonly string $subcontractAgreementId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
