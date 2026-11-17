<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProjectParticipation — an organization's authorized role on a ProjectIdentity.
 * Distinct from CRM ProjectParty and from Engagement/Contract.
 */
class ProjectParticipation extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'project_participations';

    public const STATUS_INVITED = 'invited';
    public const STATUS_PENDING = 'pending_verification';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ENDED = 'ended';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'project_identity_id',
        'participant_tenant_slug',
        'participant_network_profile_id',
        'organization_type',
        'project_role',
        'status',
        'access_scope',
        'engagement_id',
        'invitation_id',
        'effective_from',
        'effective_to',
        'source',
    ];

    protected $attributes = [
        'status' => self::STATUS_INVITED,
    ];

    protected $casts = [
        'access_scope' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function projectIdentity(): BelongsTo
    {
        return $this->belongsTo(ProjectIdentity::class, 'project_identity_id');
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class, 'engagement_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
