<?php

declare(strict_types=1);

namespace App\Inventory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StockLevel — Current quantity and AVCO cost for one item in one warehouse (CAP-071).
 *
 * Architectural constraints (TASK-008A):
 *  - Inventory module owns StockLevel (A2 MOD-09).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - inventory_item_id is an intra-module FK.
 *  - quantity_on_hand must never go below 0 in V1 — OD-009 §3.1.
 *  - average_unit_cost is the AVCO per (item × warehouse) — OD-009.
 *  - total_value = quantity_on_hand × average_unit_cost (maintained by service layer).
 *
 * AVCO formula (OD-009):
 *   new_avg = (existing_qty × existing_avg + received_qty × received_cost)
 *             / (existing_qty + received_qty)
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
class StockLevel extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'stock_levels';

    protected $fillable = [
        'tenant_slug',
        'inventory_item_id',
        'warehouse_code',
        'quantity_on_hand',
        'average_unit_cost',
        'total_value',
    ];

    protected $attributes = [
        'warehouse_code'    => 'DEFAULT',
        'quantity_on_hand'  => 0,
        'average_unit_cost' => 0,
        'total_value'       => 0,
    ];

    protected $casts = [
        'quantity_on_hand'  => 'decimal:6',
        'average_unit_cost' => 'decimal:6',
        'total_value'       => 'decimal:6',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    // Relationships

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
