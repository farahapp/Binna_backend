<?php
declare(strict_types=1);
namespace App\Accounting;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PostingRule — Rule mapping Domain Events to Journal Entries.
 * TASK-014A — Accounting Domain/Data Foundation
 * A1 MOD-14 — CAP-121 Journal Entries & Posting Rules
 * Defines how a Domain Event triggers a Journal Entry.
 */
class PostingRule extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'posting_rules';
    protected $fillable = [
        'trigger_event', 'rule_name', 'debit_account_id', 'credit_account_id',
        'amount_source', 'is_active', 'description', 'tenant_slug',
    ];
    protected $attributes = ['is_active' => true];
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function isActive(): bool { return (bool) $this->is_active; }
}
