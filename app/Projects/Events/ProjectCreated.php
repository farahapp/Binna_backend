<?php

declare(strict_types=1);

namespace App\Projects\Events;

class ProjectCreated
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $tenantSlug,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
