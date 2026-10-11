<?php

declare(strict_types=1);

namespace App\CRM;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact — CRM entity for persons associated with a Client or Lead.
 *
 * Architectural constraints (A1 MOD-04 / A2 §2.4 / A4 §4.1):
 *  - CRM owns Contact (A2 §2.4).
 *  - GetContact() contract (A4 §4.1) returns: name, role, email, phone.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - client_id is nullable — a Contact may exist before Client linkage.
 *
 * TASK-003A — CRM Domain/Data Foundation
 *
 * @property string $id
 * @property string|null $client_id
 * @property string $name
 * @property string|null $role
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string $status  'active' | 'inactive'
 * @property string|null $notes
 * @property string $tenant_slug
 */
class Contact extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — resolved by TenantContext (OD-001).
     */
    protected $connection = 'tenant';

    protected $table = 'contacts';

    protected $fillable = [
        'client_id',
        'name',
        'role',
        'email',
        'phone',
        'mobile',
        'status',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The Client this Contact belongs to.
     * Nullable — Contact may exist without a Client.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
