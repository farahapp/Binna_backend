<?php

declare(strict_types=1);

namespace App\CRM;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lead — CRM entity for prospects not yet converted to Clients.
 *
 * Architectural constraints (A1 MOD-04 / A2 §2.4):
 *  - CRM owns Lead (A2 §2.4): "عميل محتمل لم يتحول بعد".
 *  - A Lead may be converted to a Client — tracked via converted_client_id.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *
 * TASK-003A — CRM Domain/Data Foundation
 *
 * @property string $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $company
 * @property string|null $source
 * @property string $status  'new' | 'contacted' | 'qualified' | 'converted' | 'lost'
 * @property string|null $converted_client_id
 * @property \Illuminate\Support\Carbon|null $converted_at
 * @property string|null $notes
 * @property string $tenant_slug
 */
class Lead extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — resolved by TenantContext (OD-001).
     */
    protected $connection = 'tenant';

    protected $table = 'leads';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'source',
        'status',
        'converted_client_id',
        'converted_at',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The Client this Lead was converted to (if converted).
     */
    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function isLost(): bool
    {
        return $this->status === 'lost';
    }
}
