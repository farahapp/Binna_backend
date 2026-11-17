<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tenant — the root entity for every SaaS tenant.
 *
 * Lives in the shared schema (default DB connection).
 * schema_name is ALWAYS server-generated; it is never accepted from the client.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $schema_name e.g. "binaa2_tenant_acme"
 * @property string $status pending | active | suspended
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Tenant extends Model
{
    public const CLASSIFICATION_CONTRACTING_COMPANY = 'contracting_company';
    public const CLASSIFICATION_INDIVIDUAL_CONTRACTOR = 'individual_contractor';
    public const CLASSIFICATION_ENGINEERING_CONSULTANCY = 'engineering_consultancy';
    public const CLASSIFICATION_PROPERTY_OWNER = 'property_owner';
    public const CLASSIFICATION_NEEDS_REVIEW = 'needs_review';

    /** Shared-schema connection — never the per-tenant connection. */
    protected $connection = 'mysql';

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'name',
        'legal_type',
        'activity_type',
        'phone',
        'email',
        'responsible_name',
        'responsible_qid',
        'qid_expires_at',
        'status',
        // schema_name is intentionally absent — server-generated only.
    ];

    protected $hidden = [
        'schema_name', // never expose the internal schema name to API consumers
    ];

    protected $casts = [
        'qid_expires_at' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class, 'tenant_id');
    }

    public function migrations(): HasMany
    {
        return $this->hasMany(TenantMigration::class, 'tenant_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(TenantIdentifier::class, 'tenant_id');
    }

    public function classification(): array
    {
        return self::classificationFor($this->legal_type, $this->activity_type);
    }

    public static function classificationFor(?string $legalType, ?string $activityType): array
    {
        if ($legalType === null || $legalType === '') {
            return [
                'classification' => self::CLASSIFICATION_NEEDS_REVIEW,
                'classification_reason' => 'missing_legal_type',
            ];
        }

        if (! in_array($legalType, ['individual', 'organization'], true)) {
            return [
                'classification' => self::CLASSIFICATION_NEEDS_REVIEW,
                'classification_reason' => 'unsupported_legal_type',
            ];
        }

        if ($activityType === null || $activityType === '') {
            return [
                'classification' => self::CLASSIFICATION_NEEDS_REVIEW,
                'classification_reason' => 'missing_activity_type',
            ];
        }

        if (! in_array($activityType, ['contractor', 'consultant', 'both', 'owner'], true)) {
            return [
                'classification' => self::CLASSIFICATION_NEEDS_REVIEW,
                'classification_reason' => 'unsupported_activity_type',
            ];
        }

        $classification = match ([$legalType, $activityType]) {
            ['organization', 'contractor'] => self::CLASSIFICATION_CONTRACTING_COMPANY,
            ['individual', 'contractor'] => self::CLASSIFICATION_INDIVIDUAL_CONTRACTOR,
            ['organization', 'consultant'] => self::CLASSIFICATION_ENGINEERING_CONSULTANCY,
            ['organization', 'owner'] => self::CLASSIFICATION_PROPERTY_OWNER,
            ['individual', 'owner'] => self::CLASSIFICATION_PROPERTY_OWNER,
            default => null,
        };

        return [
            'classification' => $classification ?? self::CLASSIFICATION_NEEDS_REVIEW,
            'classification_reason' => $classification === null ? 'unsupported_combination' : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Derive the safe, server-controlled schema name from the slug.
     * Format: binaa2_tenant_{sanitised_slug}
     * Only lowercase alphanumeric and underscores are kept.
     */
    public static function deriveSchemaName(string $slug): string
    {
        $safe = preg_replace('/[^a-z0-9]/', '_', strtolower($slug));
        $safe = preg_replace('/_+/', '_', $safe);
        $safe = trim($safe, '_');

        return 'binaa2_tenant_'.$safe;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
