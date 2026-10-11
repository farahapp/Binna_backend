<?php
declare(strict_types=1);
namespace App\Accounting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AccountingPeriod — Monthly accounting period.
 * TASK-014A — Accounting Domain/Data Foundation
 * A1 MOD-14 — CAP-120 Chart of Accounts & Accounting Setup
 * status: OPEN | CLOSED | LOCKED
 */
class AccountingPeriod extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'accounting_periods';
    protected $fillable = [
        'period_name', 'start_date', 'end_date', 'status',
        'closed_by', 'closed_at', 'tenant_slug',
    ];
    protected $attributes = ['status' => 'OPEN'];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'period_id');
    }
    public function isOpen(): bool { return $this->status === 'OPEN'; }
    public function isClosed(): bool { return $this->status === 'CLOSED'; }
    public function isLocked(): bool { return $this->status === 'LOCKED'; }
}
