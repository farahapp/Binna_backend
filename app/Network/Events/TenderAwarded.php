<?php

declare(strict_types=1);

namespace App\Network\Events;

use App\Network\NetworkAward;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * TenderAwarded — Domain event dispatched when a Tender is awarded.
 *
 * NETWORK-005 — Award
 *
 * Consumers:
 *  - ERP Commercial Module (creates Contract/Quotation)
 *  - Notifications Module (notifies winner + unsuccessful contractors)
 *
 * Payload: tender_id, winning_bid_id, winning_network_profile_id, awarded_by, awarded_at
 * Idempotency key: tender_id (one Award per Tender)
 * Security: dispatched only after successful Award transaction commit.
 *
 * No ERP integration listeners implemented in NETWORK-005.
 */
class TenderAwarded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NetworkAward $award,
    ) {}
}
