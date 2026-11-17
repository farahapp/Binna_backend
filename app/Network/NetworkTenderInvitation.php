<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkTenderInvitation extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'network_tender_invitations';

    protected $fillable = [
        'tender_id',
        'invitee_network_profile_id',
        'invitee_tenant_slug',
        'inviter_tenant_slug',
        'status',
        'responded_at',
        'response_note',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(NetworkTender::class, 'tender_id');
    }

    public function inviteeProfile(): BelongsTo
    {
        return $this->belongsTo(NetworkProfile::class, 'invitee_network_profile_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }
}
