<?php

declare(strict_types=1);

namespace App\Network\Events;

use App\Network\NetworkBid;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BidWithdrawn
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly NetworkBid $bid) {}
}
