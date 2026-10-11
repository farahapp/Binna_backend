<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * NetworkTender — Cross-tenant Tender entity in the Construction Business Network.
 *
 * NETWORK-002 — Network Tender Foundation
 *
 * Architectural constraints (NETWORK-ARCH-001 §4 / OD-005 / OD-006):
 *  - Lives in the global (mysql) schema — NOT in any tenant schema.
 *  - Cross-tenant by design — visible to authorized Network participants per visibility rules.
 *  - publisher_profile_id references NetworkProfile (UUID, no cross-schema FK).
 *  - No Bid, Evaluation, Award workflow in this task.
 *  - No billing/monetization (OD-006 Free V1).
 *  - UUID primary key (HasUuids convention).
 *
 * Lifecycle: draft → published → closed → awarded (foundation only)
 * Visibility: public | private | invited | matched
 *
 * @property string      $id
 * @property string      $publisher_profile_id
 * @property string      $title
 * @property string|null $description
 * @property string      $category
 * @property string|null $region
 * @property \Carbon\Carbon|null $deadline
 * @property float|null  $budget_min
 * @property float|null  $budget_max
 * @property array|null  $specializations
 * @property array|null  $requirements
 * @property array|null  $participant_types
 * @property string      $status   draft|published|closed|awarded
 * @property string      $visibility  public|private|invited|matched
 * @property array|null  $match_criteria
 * @property string|null $erp_tender_id
 * @property string|null $managed_by_tenant_slug
 */
class NetworkTender extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_tenders';

    protected $fillable = [
        'publisher_profile_id',
        'title',
        'description',
        'category',
        'region',
        'deadline',
        'budget_min',
        'budget_max',
        'specializations',
        'requirements',
        'participant_types',
        'status',
        'visibility',
        'match_criteria',
        'erp_tender_id',
        'managed_by_tenant_slug',
    ];

    protected $attributes = [
        'status'     => 'draft',
        'visibility' => 'public',
    ];

    protected $casts = [
        'specializations'  => 'array',
        'requirements'     => 'array',
        'participant_types' => 'array',
        'match_criteria'   => 'array',
        'deadline'         => 'datetime',
        'budget_min'       => 'float',
        'budget_max'       => 'float',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isDraft(): bool     { return $this->status === 'draft'; }
    public function isPublished(): bool { return $this->status === 'published'; }
    public function isClosed(): bool    { return $this->status === 'closed'; }
    public function isAwarded(): bool   { return $this->status === 'awarded'; }

    // -------------------------------------------------------------------------
    // Visibility helpers
    // -------------------------------------------------------------------------

    public function isPublic(): bool   { return $this->visibility === 'public'; }
    public function isPrivate(): bool  { return $this->visibility === 'private'; }
    public function isInvited(): bool  { return $this->visibility === 'invited'; }
    public function isMatched(): bool  { return $this->visibility === 'matched'; }

    // -------------------------------------------------------------------------
    // Valid lifecycle transitions
    // -------------------------------------------------------------------------

    /**
     * Returns allowed next statuses from the current status.
     */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            'draft'     => ['published'],
            'published' => ['closed'],
            'closed'    => ['awarded'],
            'awarded'   => [],
            default     => [],
        };
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions(), true);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeByVisibility(Builder $query, string $visibility): Builder
    {
        return $query->where('visibility', $visibility);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeByRegion(Builder $query, string $region): Builder
    {
        return $query->where('region', $region);
    }

    public function scopeByPublisher(Builder $query, string $profileId): Builder
    {
        return $query->where('publisher_profile_id', $profileId);
    }

    public function scopeManagedByTenant(Builder $query, string $tenantSlug): Builder
    {
        return $query->where('managed_by_tenant_slug', $tenantSlug);
    }
}
