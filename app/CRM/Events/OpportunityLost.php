<?php

declare(strict_types=1);

namespace App\CRM\Events;

/**
 * OpportunityLost — Domain event dispatched when an Opportunity is marked Lost.
 *
 * Architectural constraints (A1 MOD-04):
 *  - Dispatched by CRMService::markOpportunityLost() after successful persistence.
 *  - Consumer: SPECIFICATION GAP — A5 does not define consumers.
 *    Dispatch required per A1 MOD-04 and MEP.
 *  - Synchronous dispatch (Laravel event()) in TASK-003B.
 *
 * TASK-003B — CRM Application/Service Layer
 */
class OpportunityLost
{
    public function __construct(
        public readonly string $opportunityId,
        public readonly string|null $clientId,
        public readonly string|null $lostReason,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $lostAt,
    ) {}
}
