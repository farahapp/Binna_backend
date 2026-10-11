<?php

declare(strict_types=1);

namespace App\Commercial\Events;

/**
 * ContractVariationApproved — Domain event dispatched when a Contract Variation is approved.
 *
 * Architectural constraints (A5 / TASK-004B1):
 *  - Plain PHP event class — no ShouldQueue, no ShouldDispatchAfterCommit.
 *  - Dispatch is owned by TASK-004B2 (approveVariation() lifecycle operation).
 *  - This class is defined in B1 as a structural contract only.
 *  - Tenant context is carried in tenantSlug (OD-001).
 *  - Both variationId and contractId are carried for consumer convenience.
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 */
class ContractVariationApproved
{
    public function __construct(
        public readonly string $variationId,
        public readonly string $contractId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
