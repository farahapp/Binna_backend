<?php

declare(strict_types=1);

namespace App\Inventory\Events;

/**
 * GoodsReceived — Domain Event emitted when a GRN is confirmed and stock is updated.
 *
 * Architectural constraints (TASK-008B / A1 MOD-09):
 *  - Emitted by GoodsReceiptService after successful GRN confirmation.
 *  - NOT emitted when the operation fails or is rejected.
 *  - Follows the existing BINAA2 event convention (immutable value object).
 *  - Consumers: Procurement (to update PO receipt status), Cost Control (to record actual cost).
 *  - No HTTP dependency.
 *
 * Normalized event name: GoodsReceived
 * (Resolves the SPECIFICATION CLARIFICATION in TASK-008 Authorization Checkpoint:
 *  GoodsReceived/GRNCreated — normalized to GoodsReceived per BINAA2 naming convention.)
 *
 * TASK-008B — Inventory Application/Service Layer
 */
class GoodsReceived
{
    public function __construct(
        public readonly string $grnId,
        public readonly string $tenantSlug,
        public readonly string $purchaseOrderId,   // UUID reference — may be empty string if no PO
        public readonly float  $totalValue,         // Total value of goods received
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
