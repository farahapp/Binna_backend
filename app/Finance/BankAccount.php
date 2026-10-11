<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BankAccount — Finance module bank account entity.
 * TASK-013A — Finance Domain/Data Foundation
 * A1 MOD-13 — CAP-111 Bank Management
 */
class BankAccount extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bank_accounts';
    protected $fillable = [
        'account_code', 'bank_name', 'account_number', 'iban',
        'currency', 'balance', 'status', 'tenant_slug',
    ];
    protected $attributes = ['currency' => 'QAR', 'balance' => 0, 'status' => 'active'];
    protected $casts = [
        'balance' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id');
    }
    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class, 'bank_account_id');
    }
    public function isActive(): bool { return $this->status === 'active'; }
}
