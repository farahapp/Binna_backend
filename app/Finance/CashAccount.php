<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CashAccount — Finance module cash fund entity.
 * TASK-013A — Finance Domain/Data Foundation
 * A1 MOD-13 — CAP-110 Cash Management
 * Schema-per-Tenant (OD-001) — no tenant_id, tenant_slug for traceability.
 */
class CashAccount extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'cash_accounts';
    protected $fillable = [
        'account_code', 'name', 'description', 'currency', 'balance',
        'status', 'tenant_slug',
    ];
    protected $attributes = ['currency' => 'QAR', 'balance' => 0, 'status' => 'active'];
    protected $casts = [
        'balance' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class, 'cash_account_id');
    }
    public function isActive(): bool { return $this->status === 'active'; }
}
