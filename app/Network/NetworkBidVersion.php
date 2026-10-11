<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NetworkBidVersion extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_bid_versions';

    protected $fillable = [
        'bid_id', 'revision_number', 'content', 'actor_id', 'reason',
        'status', 'submitted_at', 'withdrawn_at',
    ];

    protected $casts = [
        'content' => 'array',
        'revision_number' => 'integer',
        'submitted_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];
}
