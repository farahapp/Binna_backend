<?php

declare(strict_types=1);

namespace App\CRM;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Opportunity — CRM entity for potential business deals.
 *
 * Architectural constraints (A1 MOD-04 / A2 §2.4):
 *  - CRM owns Opportunity (A2 §2.4).
 *  - Lifecycle must support: creation, won, lost (TASK-003A scope).
 *  - OpportunityWon / OpportunityLost events reserved for TASK-003B.
 *  - OD-015: Pipeline/Kanban NOT implemented — List View only.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *
 * OPEN ITEM (I-002 from readiness verification):
 *  - Consumers of OpportunityWon/OpportunityLost not defined in A5.
 *  - Events will be dispatched in TASK-003B per A1 MOD-04 / MEP requirement.
 *
 * TASK-003A — CRM Domain/Data Foundation
 *
 * @property string $id
 * @property string|null $client_id
 * @property string $title
 * @property string|null $description
 * @property float|null $estimated_value
 * @property string $currency
 * @property string $status  'open' | 'won' | 'lost' | 'cancelled'
 * @property \Illuminate\Support\Carbon|null $expected_close_date
 * @property \Illuminate\Support\Carbon|null $won_at
 * @property \Illuminate\Support\Carbon|null $lost_at
 * @property string|null $lost_reason
 * @property string|null $notes
 * @property string $tenant_slug
 */
class Opportunity extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — resolved by TenantContext (OD-001).
     */
    protected $connection = 'tenant';

    protected $table = 'opportunities';

    protected $fillable = [
        'client_id',
        'title',
        'description',
        'estimated_value',
        'currency',
        'status',
        'expected_close_date',
        'won_at',
        'lost_at',
        'lost_reason',
        'notes',
        'tenant_slug',
    ];

    protected $casts = [
        'estimated_value'     => 'decimal:2',
        'expected_close_date' => 'date',
        'won_at'              => 'datetime',
        'lost_at'             => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The Client this Opportunity belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * CRM Activities related to this Opportunity.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CRMActivity::class, 'subject_id')
                    ->where('subject_type', self::class);
    }

    // -------------------------------------------------------------------------
    // Status helpers — support lifecycle for Won/Lost events (TASK-003B)
    // -------------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isWon(): bool
    {
        return $this->status === 'won';
    }

    public function isLost(): bool
    {
        return $this->status === 'lost';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Returns true if the Opportunity is in a terminal state.
     * Terminal states: won, lost, cancelled.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['won', 'lost', 'cancelled'], true);
    }
}
