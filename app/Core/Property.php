<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Property — real-estate / asset owned by an Owner organization.
 * Shared (mysql) schema. Creating a project does NOT grant property ownership.
 */
class Property extends Model
{
    use HasUuids;

    protected $connection = 'mysql';

    protected $table = 'properties';

    protected $fillable = [
        'owner_tenant_slug',
        'owner_network_profile_id',
        'name',
        'property_type',
        'address_line',
        'city',
        'region',
        'country',
        'plot_number',
        'title_deed_ref',
        'ownership_status',
        'metadata',
    ];

    protected $attributes = [
        'property_type' => 'real_estate',
        'country' => 'QA',
        'ownership_status' => 'claimed',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function projectIdentities(): HasMany
    {
        return $this->hasMany(ProjectIdentity::class, 'property_id');
    }

    public function isOwnedBy(string $tenantSlug): bool
    {
        return $this->owner_tenant_slug === $tenantSlug;
    }
}
