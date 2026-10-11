<?php

declare(strict_types=1);

namespace App\Equipment;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asset — Equipment module main entity.
 *
 * Architectural constraints (TASK-011A):
 *  - Equipment module owns Asset (A2 Domain Ownership Matrix — MOD-12).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - project_id is a UUID reference — no cross-schema FK.
 *  - No Equipment Rental fields — OD-012 IMPL-DEFERRABLE (V2/Future).
 *
 * Lifecycle: active | under_maintenance | decommissioned | disposed
 *
 * TASK-011A — Equipment Domain/Data Foundation
 */
class Asset extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'assets';

    protected $fillable = [
        'asset_code',
        'name',
        'description',
        'category',
        'asset_type',
        'status',
        'acquisition_date',
        'acquisition_cost',
        'currency',
        'current_value',
        'depreciation_rate',
        'project_id',
        'assigned_at',
        'available_from',
        'notes',
        'tenant_slug',
    ];

    protected $attributes = [
        'status'     => 'active',
        'asset_type' => 'owned',
        'currency'   => 'QAR',
    ];

    protected $casts = [
        'acquisition_date'  => 'date',
        'available_from'    => 'date',
        'assigned_at'       => 'datetime',
        'acquisition_cost'  => 'decimal:4',
        'current_value'     => 'decimal:4',
        'depreciation_rate' => 'decimal:4',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function allocations(): HasMany
    {
        return $this->hasMany(EquipmentAllocation::class, 'asset_id');
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class, 'asset_id');
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(EquipmentCostAllocation::class, 'asset_id');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isActive(): bool { return $this->status === 'active'; }
    public function isUnderMaintenance(): bool { return $this->status === 'under_maintenance'; }
    public function isDecommissioned(): bool { return $this->status === 'decommissioned'; }
    public function isAvailable(): bool { return $this->isActive() && is_null($this->project_id); }
}
