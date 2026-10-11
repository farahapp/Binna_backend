<?php

declare(strict_types=1);

namespace App\Commercial\Events;

/**
 * ContractSigned — Domain event dispatched when a Contract is signed.
 *
 * Architectural constraints (A5 / TASK-004B1):
 *  - Plain PHP event class — no ShouldQueue, no ShouldDispatchAfterCommit.
 *  - Dispatch is owned by TASK-004B2 (signContract() lifecycle operation).
 *  - This class is defined in B1 as a structural contract only.
 *  - Tenant context is carried in tenantSlug (OD-001).
 *
 * TASK-004B1 — Commercial Service Foundation + Public Contracts
 */
class ContractSigned
{
    public function __construct(
        public readonly string $contractId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
