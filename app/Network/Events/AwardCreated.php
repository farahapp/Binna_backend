<?php

declare(strict_types=1);

namespace App\Network\Events;

use App\Network\NetworkAward;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AwardCreated — Internal domain event dispatched when a NetworkAward is created.
 *
 * NETWORK-005 — Award
 *
 * Internal to the Network domain. No listeners in V1.
 */
class AwardCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NetworkAward $award,
    ) {}
}
