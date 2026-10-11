<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TenantMigration — tracks per-tenant migration status in the shared schema.
 *
 * Implements the NEW-002 Two-Track Migration Architecture.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $migration
 * @property int $batch
 * @property string $status applied | failed | rolled_back
 * @property Carbon|null $applied_at
 */
class TenantMigration extends Model
{
    protected $connection = 'mysql';

    protected $table = 'tenant_migrations';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'migration',
        'batch',
        'status',
        'applied_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
