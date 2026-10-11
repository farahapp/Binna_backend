<?php

declare(strict_types=1);

namespace App\Inventory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * InventoryItem — Master catalogue entry for a stockable material/item (CAP-070 / CAP-071).
 *
 * Architectural constraints (TASK-008A):
 *  - Inventory module owns InventoryItem (A2 MOD-09).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - average_unit_cost is the current AVCO value — maintained by GoodsReceiptService.
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
class InventoryItem extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'inventory_items';

    protected $fillable = [
        'tenant_slug',
        'item_code',
        'name',
        'description',
        'unit_of_measure',
        'average_unit_cost',
        'status',
    ];

    protected $attributes = [
        'average_unit_cost' => 0,
        'status'            => 'active',
    ];

    protected $casts = [
        'average_unit_cost' => 'decimal:6',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    // Relationships

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'inventory_item_id');
    }

    public function grnItems(): HasMany
    {
        return $this->hasMany(GRNItem::class, 'inventory_item_id');
    }

    // Status helpers

    public function isActive(): bool { return $this->status === 'active'; }
    public function isInactive(): bool { return $this->status === 'inactive'; }
}
