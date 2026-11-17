<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkClarification extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'network_clarifications';

    protected $fillable = [
        'tender_id',
        'bid_id',
        'parent_id',
        'asked_by_tenant_slug',
        'asked_by_profile_id',
        'directed_to_tenant_slug',
        'question',
        'response',
        'status',
        'answered_at',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(NetworkTender::class, 'tender_id');
    }
}
