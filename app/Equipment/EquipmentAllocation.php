<?php

declare(strict_types=1);

namespace App\Equipment;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EquipmentAllocation — tracks asset-to-project allocation history.
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class EquipmentAllocation extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'equipment_allocations';

    protected $fillable = [
        'asset_id',
        'project_id',
        'allocated_by',
        'allocated_at',
        'released_at',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
        'released_at'  => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
