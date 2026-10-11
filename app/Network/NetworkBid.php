<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkBid extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_bids';

    protected $fillable = [
        'tender_id', 'contractor_profile_id', 'effective_version_id', 'status',
        'latest_revision_number', 'submitted_at', 'withdrawn_at', 'managed_by_tenant_slug',
    ];

    protected $casts = [
        'latest_revision_number' => 'integer',
        'submitted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(NetworkBidVersion::class, 'bid_id')->orderBy('revision_number');
    }
}
