<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Collection — Collection recording against a receivable.
 * TASK-013A — Finance Domain/Data Foundation
 * A1 MOD-13 — CAP-181 Receivables & Collections
 */
class Collection extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'collections';
    protected $fillable = [
        'receivable_id', 'invoice_id', 'amount', 'collection_date',
        'payment_method', 'reference', 'notes', 'recorded_by', 'tenant_slug',
        'bank_account_id', 'cash_account_id',
    ];
    protected $casts = [
        'amount' => 'decimal:4',
        'collection_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'receivable_id');
    }
}
