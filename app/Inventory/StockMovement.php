<?php

declare(strict_types=1);

namespace App\Inventory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StockMovement — Records every stock IN / OUT / TRANSFER movement (CAP-071).
 *
 * Architectural constraints (TASK-012A):
 *  - Inventory module owns StockMovement (A2 MOD-09).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - inventory_item_id is an intra-module FK.
 *  - reference_id is a UUID reference to the source document (GRN, PO, etc.) — NO cross-schema FK.
 *
 * Movement types:
 *  - in       : Goods received (GRN confirmation)
 *  - out      : Stock issued/consumed (project consumption)
 *  - transfer : Stock moved between warehouses
 *
 * TASK-012A — Inventory Domain/Data Foundation (Completion)
 */
class StockMovement extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'stock_movements';

    protected $fillable = [
        'tenant_slug',
        'inventory_item_id',
        'movement_type',
        'quantity',
        'unit_cost',
        'total_value',
        'from_warehouse_code',
        'to_warehouse_code',
        'reference_type',
        'reference_id',
        'notes',
        'performed_by',
    ];

    protected $casts = [
        'quantity'    => 'decimal:6',
        'unit_cost'   => 'decimal:6',
        'total_value' => 'decimal:6',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    // Relationships

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    // Type helpers

    public function isIn(): bool { return $this->movement_type === 'in'; }
    public function isOut(): bool { return $this->movement_type === 'out'; }
    public function isTransfer(): bool { return $this->movement_type === 'transfer'; }
}
