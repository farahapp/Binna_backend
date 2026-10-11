<?php

declare(strict_types=1);

namespace App\Network\Events;

use App\Network\NetworkTender;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * TenderPublished — Domain Event dispatched when a Tender transitions to 'published'.
 *
 * NETWORK-002 — Network Tender Foundation
 * NETWORK-ARCH-001 §5 — Domain Events
 *
 * Payload (per architecture):
 *  - tender_listing_id  : string (UUID)
 *  - visibility_type    : string (public|private|invited|matched)
 *  - category           : string
 *  - region             : string|null
 *  - deadline           : string|null (ISO 8601)
 *
 * Idempotency Key: tender_listing_id (one tender = one published event)
 *
 * Constraints:
 *  - No Bid, Evaluation, Award, ERP Contract, or ERP Project behavior.
 *  - No speculative listeners created in this task.
 */
class TenderPublished
{
    use Dispatchable, SerializesModels;

    public readonly string $tenderListingId;
    public readonly string $visibilityType;
    public readonly string $category;
    public readonly ?string $region;
    public readonly ?string $deadline;

    public function __construct(NetworkTender $tender)
    {
        $this->tenderListingId = $tender->id;
        $this->visibilityType  = $tender->visibility;
        $this->category        = $tender->category;
        $this->region          = $tender->region;
        $this->deadline        = $tender->deadline?->toIso8601String();
    }
}
