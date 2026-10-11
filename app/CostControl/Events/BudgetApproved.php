<?php

declare(strict_types=1);

namespace App\CostControl\Events;

class BudgetApproved
{
    public function __construct(
        public readonly string $budgetId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
