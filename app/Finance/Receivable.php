<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receivable — Accounts receivable tracking.
 * TASK-013A — Finance Domain/Data Foundation
 * A1 MOD-13 — CAP-181 Receivables & Collections
 * status: OPEN | PARTIALLY_COLLECTED | COLLECTED | WRITTEN_OFF
 */
class Receivable extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'receivables';
    protected $fillable = [
        'invoice_id', 'client_id', 'original_amount', 'collected_amount',
        'outstanding_amount', 'due_date', 'status', 'tenant_slug',
    ];
    protected $attributes = [
        'status' => 'OPEN',
        'collected_amount' => 0,
    ];
    protected $casts = [
        'original_amount' => 'decimal:4',
        'collected_amount' => 'decimal:4',
        'outstanding_amount' => 'decimal:4',
        'due_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class, 'receivable_id');
    }
    public function isOpen(): bool { return $this->status === 'OPEN'; }
    public function isCollected(): bool { return $this->status === 'COLLECTED'; }
}
