<?php

declare(strict_types=1);

namespace App\CostControl\Events;

class BudgetExceeded
{
    public function __construct(
        public readonly string $budgetId,
        public readonly string $tenantSlug,
        public readonly float $budgetAmount,
        public readonly float $committedAmount,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
