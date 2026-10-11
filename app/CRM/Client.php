<?php

declare(strict_types=1);

namespace App\CRM;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client — CRM master entity for companies and individuals.
 *
 * Architectural constraints (A1 MOD-04 / A2 §2.4 / A4 §4.1):
 *  - CRM is the single owner of Client master data.
 *  - Commercial and Finance read Client via GetClient() contract — NOT via this model directly.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - tenant_slug stored for cross-schema traceability.
 *  - Supports type: 'company' | 'individual' (A1 MOD-04).
 *  - Status: 'active' | 'inactive' | 'blocked' — required for ValidateClient() contract.
 *
 * Forbidden dependencies (A1 MOD-04):
 *  - MUST NOT depend on Accounting or Finance modules.
 *
 * Events reserved for TASK-003B:
 *  - ClientCreated (A1 MOD-04 — OPEN ITEM: A5 notes no clear consumer; MEP requires dispatch)
 *
 * TASK-003A — CRM Domain/Data Foundation
 *
 * @property string $id
 * @property string $type  'company' | 'individual'
 * @property string $name
 * @property string|null $trade_name
 * @property string|null $registration_number
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $country
 * @property string|null $city
 * @property string $status  'active' | 'inactive' | 'blocked'
 * @property string|null $notes
 * @property string $tenant_slug
 */
class Client extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — resolved by TenantContext (OD-001).
     */
    protected $connection = 'tenant';

    protected $table = 'clients';

    protected $fillable = [
        'type',
        'name',
        'trade_name',
        'registration_number',
        'email',
        'phone',
        'address',
        'country',
        'city',
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
     * Contacts associated with this Client.
     * A Client may have multiple Contacts (A1 MOD-04).
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'client_id');
    }

    /**
     * Opportunities associated with this Client.
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'client_id');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true if the client is active and valid for business transactions.
     * Used by ValidateClient() contract (A4 §4.1).
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompany(): bool
    {
        return $this->type === 'company';
    }

    public function isIndividual(): bool
    {
        return $this->type === 'individual';
    }
}
