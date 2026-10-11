<?php

declare(strict_types=1);

namespace App\Equipment\Events;

/**
 * AssetAllocated — fired when an asset is allocated to a project.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class AssetAllocated
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $projectId,
        public readonly string $tenantSlug,
    ) {}
}
