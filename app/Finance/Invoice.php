<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Invoice — Client billing invoice.
 * TASK-013A — Finance Domain/Data Foundation
 * A1 MOD-13 — CAP-180 Client Billing & Invoicing
 * status: DRAFT | ISSUED | PAID | PARTIALLY_PAID | CANCELLED
 */
class Invoice extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'invoices';
    protected $fillable = [
        'invoice_number', 'client_id', 'project_id', 'contract_id',
        'invoice_date', 'due_date', 'subtotal', 'tax_amount', 'total_amount',
        'paid_amount', 'status', 'notes', 'issued_by', 'tenant_slug',
    ];
    protected $attributes = [
        'status' => 'DRAFT',
        'subtotal' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
    ];
    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }
    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class, 'invoice_id');
    }
    public function isDraft(): bool { return $this->status === 'DRAFT'; }
    public function isIssued(): bool { return $this->status === 'ISSUED'; }
    public function isPaid(): bool { return $this->status === 'PAID'; }
    public function isCancelled(): bool { return $this->status === 'CANCELLED'; }
    public function remainingAmount(): float { return (float)$this->total_amount - (float)$this->paid_amount; }
}
