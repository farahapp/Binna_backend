<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ProjectIdentity — canonical identity of a real construction project.
 * Shared schema. Distinct from tenant-local Project execution workspaces.
 */
class ProjectIdentity extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'project_identities';

    protected $fillable = [
        'property_id',
        'canonical_code',
        'name',
        'description',
        'region',
        'status',
        'created_by_tenant_slug',
        'created_by_org_type',
        'verified_owner_tenant_slug',
        'match_keys',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected $casts = [
        'match_keys' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ProjectParticipation::class, 'project_identity_id');
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class, 'project_identity_id');
    }
}
