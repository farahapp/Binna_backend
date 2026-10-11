<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BankReconciliation — bank reconciliation record.
 * TASK-013A — Finance Domain/Data Foundation
 * status: DRAFT | COMPLETED
 */
class BankReconciliation extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bank_reconciliations';
    protected $fillable = [
        'bank_account_id', 'period_start', 'period_end',
        'statement_balance', 'book_balance', 'difference',
        'status', 'reconciled_by', 'reconciled_at', 'notes', 'tenant_slug',
    ];
    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'statement_balance' => 'decimal:4',
        'book_balance' => 'decimal:4',
        'difference' => 'decimal:4',
        'reconciled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
    public function isCompleted(): bool { return $this->status === 'COMPLETED'; }
    public function isDraft(): bool { return $this->status === 'DRAFT'; }
}
