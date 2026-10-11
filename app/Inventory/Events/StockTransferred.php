<?php

declare(strict_types=1);

namespace App\Inventory\Events;

/**
 * StockTransferred — Domain event fired when stock is transferred between warehouses.
 *
 * TASK-012B — Inventory Application/Service Layer (Completion)
 */
class StockTransferred
{
    public function __construct(
        public readonly string      $tenantSlug,
        public readonly string      $inventoryItemId,
        public readonly string      $fromWarehouseCode,
        public readonly string      $toWarehouseCode,
        public readonly float       $quantity,
        public readonly float       $unitCost,
        public readonly string|null $performedBy,
    ) {}
}
