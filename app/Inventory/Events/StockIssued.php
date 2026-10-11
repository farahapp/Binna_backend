<?php

declare(strict_types=1);

namespace App\Inventory\Events;

/**
 * StockIssued — Domain event fired when stock is issued/consumed (OUT movement).
 *
 * Consumed by Cost Control module to record material consumption against a project.
 *
 * Architectural constraints (TASK-012B):
 *  - Event boundary only — no Accounting journal entries.
 *  - Cost Control listens to this event to record cost allocation.
 *  - No cross-module direct calls — event-driven integration only.
 *
 * TASK-012B — Inventory Application/Service Layer (Completion)
 */
class StockIssued
{
    public function __construct(
        public readonly string      $tenantSlug,
        public readonly string      $inventoryItemId,
        public readonly string      $warehouseCode,
        public readonly float       $quantity,
        public readonly float       $unitCost,
        public readonly float       $totalValue,
        public readonly string|null $projectId,
        public readonly string|null $referenceId,
        public readonly string|null $performedBy,
    ) {}
}
