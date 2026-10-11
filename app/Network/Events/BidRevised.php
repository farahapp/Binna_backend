<?php

declare(strict_types=1);

namespace App\Network\Events;

use App\Network\NetworkBid;
use App\Network\NetworkBidVersion;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BidRevised
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NetworkBid $bid,
        public readonly NetworkBidVersion $version,
    ) {}
}
