<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BankTransaction — bank transaction record.
 * TASK-013A — Finance Domain/Data Foundation
 * type: CREDIT | DEBIT
 */
class BankTransaction extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bank_transactions';
    protected $fillable = [
        'bank_account_id', 'type', 'amount', 'reference', 'description',
        'transaction_date', 'is_reconciled', 'reconciliation_id', 'recorded_by', 'tenant_slug',
    ];
    protected $casts = [
        'amount' => 'decimal:4',
        'transaction_date' => 'date',
        'is_reconciled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
    public function isCredit(): bool { return $this->type === 'CREDIT'; }
    public function isDebit(): bool { return $this->type === 'DEBIT'; }
    public function isReconciled(): bool { return (bool) $this->is_reconciled; }
}
