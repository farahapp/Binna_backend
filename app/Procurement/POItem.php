<?php

declare(strict_types=1);

namespace App\Procurement;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POItem — Line item of a Purchase Order (CAP-063).
 *
 * Architectural constraints (TASK-007A):
 *  - Procurement module owns POItem (MOD-08).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - purchase_order_id is an intra-module FK (within Procurement).
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class POItem extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'po_items';

    protected $fillable = [
        'purchase_order_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'total_price',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    // Relationships

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
