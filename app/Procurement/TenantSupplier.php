<?php

declare(strict_types=1);

namespace App\Procurement;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TenantSupplier — Supplier registry for a tenant (CAP-061/062/063).
 *
 * Architectural constraints (TASK-007A):
 *  - Procurement module owns TenantSupplier (MOD-08).
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-007A — Procurement Domain/Data Foundation
 */
class TenantSupplier extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'tenant_suppliers';

    protected $fillable = [
        'tenant_slug',
        'name',
        'code',
        'contact_name',
        'contact_email',
        'contact_phone',
        'address',
        'is_active',
        // OD-005 — Dual Identity + Optional Link (NETWORK-001)
        // Nullable UUID reference to a Network Profile (cross-schema, no FK).
        // ERP Supplier does NOT automatically become a Network Profile.
        'network_profile_id',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class, 'supplier_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    // Status helpers

    public function isActive(): bool { return $this->is_active === true; }
}
