<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItem — Invoice line item.
 * TASK-013A — Finance Domain/Data Foundation
 */
class InvoiceItem extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'invoice_items';
    protected $fillable = [
        'invoice_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'line_total', 'tenant_slug',
    ];
    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
