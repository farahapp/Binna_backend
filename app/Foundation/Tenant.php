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
    /** Shared-schema connection — never the per-tenant connection. */
    protected $connection = 'mysql';

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'name',
        'status',
        // schema_name is intentionally absent — server-generated only.
    ];

    protected $hidden = [
        'schema_name', // never expose the internal schema name to API consumers
    ];

    protected $casts = [
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
