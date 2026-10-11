<?php

declare(strict_types=1);

namespace App\Inventory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GRNItem — One line of a GoodsReceiptNote (CAP-070).
 *
 * Architectural constraints (TASK-008A):
 *  - Inventory module owns GRNItem (A2 MOD-09).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - UUID primary key (HasUuids convention).
 *  - grn_id is an intra-module FK (GoodsReceiptNote).
 *  - inventory_item_id is an intra-module FK (InventoryItem).
 *  - po_item_id is a UUID reference to Procurement POItem — NO cross-schema FK.
 *  - unit_cost feeds the AVCO calculation when the GRN is confirmed (OD-009).
 *  - line_total = quantity_received × unit_cost (maintained by service layer).
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
class GRNItem extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'grn_items';

    protected $fillable = [
        'grn_id',
        'inventory_item_id',
        'po_item_id',
        'quantity_received',
        'unit_cost',
        'line_total',
        'notes',
    ];

    protected $attributes = [
        'unit_cost'  => 0,
        'line_total' => 0,
    ];

    protected $casts = [
        'quantity_received' => 'decimal:6',
        'unit_cost'         => 'decimal:6',
        'line_total'        => 'decimal:6',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    // Relationships

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'grn_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
