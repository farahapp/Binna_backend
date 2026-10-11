<?php
declare(strict_types=1);
namespace App\Procurement\Events;

class RFQSent
{
    public function __construct(
        public readonly string $rfqId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
