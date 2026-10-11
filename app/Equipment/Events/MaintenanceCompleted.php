<?php

declare(strict_types=1);

namespace App\Equipment\Events;

/**
 * MaintenanceCompleted — fired when a maintenance record is completed.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class MaintenanceCompleted
{
    public function __construct(
        public readonly string $maintenanceRecordId,
        public readonly string $assetId,
        public readonly string $tenantSlug,
    ) {}
}
