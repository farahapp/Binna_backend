<?php

declare(strict_types=1);

namespace App\Equipment\Events;

/**
 * AssetDecommissioned — fired when an asset is decommissioned.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class AssetDecommissioned
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $tenantSlug,
    ) {}
}
