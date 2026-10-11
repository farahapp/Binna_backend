<?php

declare(strict_types=1);

namespace App\Equipment;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MaintenanceRecord — tracks maintenance events for assets.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class MaintenanceRecord extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'maintenance_records';

    protected $fillable = [
        'asset_id',
        'maintenance_type',
        'status',
        'scheduled_date',
        'started_at',
        'completed_at',
        'cost',
        'currency',
        'description',
        'performed_by',
        'notes',
        'tenant_slug',
    ];

    protected $attributes = [
        'status'   => 'pending',
        'currency' => 'QAR',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
        'cost'           => 'decimal:4',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
