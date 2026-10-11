<?php
declare(strict_types=1);
namespace App\Accounting\Events;

/**
 * PeriodClosed — fired when an Accounting Period is closed.
 * TASK-014B — Accounting Application/Service Layer
 */
class PeriodClosed
{
    public function __construct(
        public readonly string $periodId,
        public readonly string $periodName,
        public readonly string $tenantSlug,
    ) {}
}
