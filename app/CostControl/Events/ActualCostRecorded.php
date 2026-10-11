<?php

declare(strict_types=1);

namespace App\CostControl\Events;

class ActualCostRecorded
{
    public function __construct(
        public readonly string $actualCostId,
        public readonly string $budgetId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
