<?php
declare(strict_types=1);
namespace App\Procurement\Events;

class PurchaseOrderIssued
{
    public function __construct(
        public readonly string $purchaseOrderId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
