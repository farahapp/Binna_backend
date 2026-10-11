<?php

declare(strict_types=1);

namespace App\Equipment\Events;

/**
 * DepreciationRecorded — fired when depreciation is recorded for an asset.
 * Accounting module listens and creates Journal Entry.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class DepreciationRecorded
{
    public function __construct(
        public readonly string $assetId,
        public readonly float $depreciationAmount,
        public readonly string $currency,
        public readonly string $tenantSlug,
    ) {}
}
