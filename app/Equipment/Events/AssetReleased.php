<?php

declare(strict_types=1);

namespace App\Equipment\Events;

/**
 * AssetReleased — fired when an asset is released from a project.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class AssetReleased
{
    public function __construct(
        public readonly string $assetId,
        public readonly string|null $projectId,
        public readonly string $tenantSlug,
    ) {}
}
