<?php
declare(strict_types=1);
namespace App\Accounting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JournalLine — Journal entry line (debit or credit).
 * TASK-014A — Accounting Domain/Data Foundation
 * A1 MOD-14 — CAP-121 Journal Entries & Posting Rules
 */
class JournalLine extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'journal_lines';
    protected $fillable = [
        'entry_id', 'account_id', 'debit', 'credit',
        'project_id', 'cost_code_id', 'department_id',
        'description', 'tenant_slug',
    ];
    protected $attributes = ['debit' => 0, 'credit' => 0];
    protected $casts = [
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
    public function isDebitLine(): bool { return (float)$this->debit > 0; }
    public function isCreditLine(): bool { return (float)$this->credit > 0; }
}
