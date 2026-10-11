<?php

declare(strict_types=1);

namespace App\Commercial\Events;

/**
 * QuotationAccepted — Domain event dispatched when a Quotation is accepted.
 *
 * Architectural constraints (A5 / TASK-004B1):
 *  - Plain PHP event class — no ShouldQueue, no ShouldDispatchAfterCommit.
 *  - Dispatch is owned by TASK-004B2 (quotation acceptance lifecycle operation).
 *  - This class is defined in B1 as a structural contract only.
 *  - Tenant context is carried in tenantSlug (OD-001).
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 */
class QuotationAccepted
{
    public function __construct(
        public readonly string $quotationId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
