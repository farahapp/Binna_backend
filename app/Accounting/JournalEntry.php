<?php
declare(strict_types=1);
namespace App\Accounting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * JournalEntry — Double-entry journal entry header.
 * TASK-014A — Accounting Domain/Data Foundation
 * A1 MOD-14 — CAP-121 Journal Entries & Posting Rules
 * status: DRAFT | POSTED | REVERSED
 * IMMUTABLE after posting (DEC-013)
 */
class JournalEntry extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'journal_entries';
    protected $fillable = [
        'reference', 'source_module', 'source_document_id', 'source_document_type',
        'posting_date', 'period_id', 'status', 'narration',
        'created_by', 'posted_at', 'reversal_of', 'tenant_slug',
    ];
    protected $attributes = ['status' => 'DRAFT'];
    protected $casts = [
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }
    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isPosted(): bool { return $this->status === 'POSTED'; }
    public function isReversed(): bool { return $this->status === 'REVERSED'; }
}
