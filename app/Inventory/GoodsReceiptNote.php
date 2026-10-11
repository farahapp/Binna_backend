<?php

declare(strict_types=1);

namespace App\Inventory;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GoodsReceiptNote — Physical receipt of goods against a Purchase Order (CAP-070).
 *
 * Architectural constraints (TASK-008A):
 *  - Inventory module owns GoodsReceiptNote (A2 MOD-09).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *  - purchase_order_id is a UUID reference to Procurement — NO cross-schema FK.
 *  - supplier_id is a UUID reference to Procurement TenantSupplier — NO cross-schema FK.
 *  - received_by is a UUID reference to IAM User — NO cross-schema FK.
 *  - Stock is updated ONLY when status transitions to 'confirmed'.
 *
 * Lifecycle: draft → confirmed → cancelled
 *
 * TASK-008A — Inventory Domain/Data Foundation
 */
class GoodsReceiptNote extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'goods_receipt_notes';

    protected $fillable = [
        'tenant_slug',
        'grn_number',
        'purchase_order_id',
        'supplier_id',
        'warehouse_code',
        'status',
        'received_date',
        'received_by',
        'notes',
    ];

    protected $attributes = [
        'status'         => 'draft',
        'warehouse_code' => 'DEFAULT',
    ];

    protected $casts = [
        'received_date' => 'date',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // Relationships

    public function items(): HasMany
    {
        return $this->hasMany(GRNItem::class, 'grn_id');
    }

    // Status helpers

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isConfirmed(): bool { return $this->status === 'confirmed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
