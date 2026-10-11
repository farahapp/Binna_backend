<?php
declare(strict_types=1);
namespace App\Procurement\Events;

class PurchaseRequestApproved
{
    public function __construct(
        public readonly string $purchaseRequestId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
