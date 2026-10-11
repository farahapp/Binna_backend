<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CashTransaction — cash in/out transaction record.
 * TASK-013A — Finance Domain/Data Foundation
 * type: IN | OUT
 */
class CashTransaction extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'cash_transactions';
    protected $fillable = [
        'cash_account_id', 'type', 'amount', 'reference', 'description',
        'transaction_date', 'recorded_by', 'tenant_slug',
    ];
    protected $casts = [
        'amount' => 'decimal:4',
        'transaction_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }
    public function isIn(): bool { return $this->type === 'IN'; }
    public function isOut(): bool { return $this->type === 'OUT'; }
}
