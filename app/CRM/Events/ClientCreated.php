<?php

declare(strict_types=1);

namespace App\CRM\Events;

/**
 * ClientCreated — Domain event dispatched when a new Client is created.
 *
 * Architectural constraints (A1 MOD-04):
 *  - Dispatched by CRMService::createClient() after successful persistence.
 *  - Consumer: SPECIFICATION GAP — A5 classifies as no async consumer;
 *    A1 MOD-04 requires dispatch. MEP requires dispatch.
 *    Dispatch is required; consumer remains undefined.
 *  - Synchronous dispatch (Laravel event()) in TASK-003B.
 *    Async queue-based dispatch is deferred to a later infrastructure slice.
 *
 * TASK-003B — CRM Application/Service Layer
 */
class ClientCreated
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $type,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
