<?php

declare(strict_types=1);

namespace App\Equipment\Events;

/**
 * AssetRegistered — fired when a new asset is registered.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class AssetRegistered
{
    public function __construct(
        public readonly string $assetId,
        public readonly string $assetCode,
        public readonly string $tenantSlug,
    ) {}
}
