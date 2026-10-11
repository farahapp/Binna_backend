<?php

declare(strict_types=1);

namespace App\Subcontracts\Events;

/**
 * SubcontractCertificateApproved — Domain event raised when a subcontract certificate is approved (TASK-009A).
 *
 * TASK-009A — Subcontract Domain/Data Foundation
 */
class SubcontractCertificateApproved
{
    public function __construct(
        public readonly string $subcontractCertificateId,
        public readonly string $subcontractAgreementId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
