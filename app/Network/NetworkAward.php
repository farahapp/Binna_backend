<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NetworkAward — Cross-tenant Award entity.
 *
 * NETWORK-005 — Award
 *
 * V1 Award is final — no revocation, amendment, or cancellation.
 * One Award per Tender (unique constraint on tender_id).
 *
 * @property string      $id
 * @property string      $tender_id
 * @property string      $winning_bid_id
 * @property string      $winning_network_profile_id
 * @property string      $awarded_by
 * @property \Carbon\Carbon $awarded_at
 * @property string|null $reason
 * @property string|null $managed_by_tenant_slug
 */
class NetworkAward extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_awards';

    protected $fillable = [
        'tender_id',
        'winning_bid_id',
        'winning_network_profile_id',
        'awarded_by',
        'awarded_at',
        'reason',
        'managed_by_tenant_slug',
    ];

    protected $casts = [
        'awarded_at' => 'datetime',
    ];
}
