<?php

declare(strict_types=1);

namespace App\Network\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BidRevisionRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $bidId,
        public readonly string $actorId,
        public readonly string $reason,
    ) {}
}
