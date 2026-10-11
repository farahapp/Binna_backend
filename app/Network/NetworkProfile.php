<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * NetworkProfile — Cross-tenant identity in the Construction Business Network.
 *
 * NETWORK-001 — Network Foundation & Profile
 *
 * Architectural constraints (NETWORK-ARCH-001 / OD-005):
 *  - Lives in the global (mysql) schema — NOT in any tenant schema.
 *  - Cross-tenant by design — visible to all authorized Network participants.
 *  - Independent of TenantSupplier (ERP) — Dual Identity model (OD-005).
 *  - No direct cross-schema DB coupling.
 *  - UUID primary key (HasUuids convention).
 *
 * @property string $id
 * @property string $company_name
 * @property string $profile_type  owner|engineer|contractor|main_contractor|subcontractor|supplier
 * @property string $status        draft|active|suspended|deactivated
 * @property string|null $region
 * @property array|null  $specializations
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $website
 * @property string|null $description
 * @property string|null $managed_by_tenant_slug
 */
class NetworkProfile extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_profiles';

    protected $fillable = [
        'company_name',
        'profile_type',
        'status',
        'region',
        'specializations',
        'contact_email',
        'contact_phone',
        'website',
        'description',
        'managed_by_tenant_slug',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $casts = [
        'specializations' => 'array',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isDraft(): bool      { return $this->status === 'draft'; }
    public function isActive(): bool     { return $this->status === 'active'; }
    public function isSuspended(): bool  { return $this->status === 'suspended'; }
    public function isDeactivated(): bool { return $this->status === 'deactivated'; }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('profile_type', $type);
    }

    public function scopeByRegion(Builder $query, string $region): Builder
    {
        return $query->where('region', $region);
    }

    public function scopeManagedByTenant(Builder $query, string $tenantSlug): Builder
    {
        return $query->where('managed_by_tenant_slug', $tenantSlug);
    }
}
