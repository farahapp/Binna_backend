<?php
declare(strict_types=1);
namespace App\Accounting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Account — Chart of Accounts entry.
 * TASK-014A — Accounting Domain/Data Foundation
 * A1 MOD-14 — CAP-120 Chart of Accounts & Accounting Setup
 * account_type: ASSET | LIABILITY | EQUITY | REVENUE | EXPENSE
 * status: active | inactive
 */
class Account extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'accounts';
    protected $fillable = [
        'account_code', 'name', 'account_type', 'parent_id',
        'is_control_account', 'description', 'status', 'tenant_slug',
    ];
    protected $attributes = [
        'status' => 'active',
        'is_control_account' => false,
    ];
    protected $casts = [
        'is_control_account' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isAsset(): bool { return $this->account_type === 'ASSET'; }
    public function isLiability(): bool { return $this->account_type === 'LIABILITY'; }
    public function isEquity(): bool { return $this->account_type === 'EQUITY'; }
    public function isRevenue(): bool { return $this->account_type === 'REVENUE'; }
    public function isExpense(): bool { return $this->account_type === 'EXPENSE'; }
}
