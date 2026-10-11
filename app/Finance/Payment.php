<?php
declare(strict_types=1);
namespace App\Finance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment — Payment record (supplier or client).
 * TASK-013A — Finance Domain/Data Foundation
 * payment_type: SUPPLIER | CLIENT
 * payment_method: CASH | BANK_TRANSFER | CHEQUE
 */
class Payment extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'payments';
    protected $fillable = [
        'payment_number', 'payment_type', 'payment_method',
        'party_id', 'party_type', 'invoice_id', 'bank_account_id',
        'amount', 'payment_date', 'reference', 'notes',
        'recorded_by', 'tenant_slug',
    ];
    protected $casts = [
        'amount' => 'decimal:4',
        'payment_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function isSupplierPayment(): bool { return $this->payment_type === 'SUPPLIER'; }
    public function isClientPayment(): bool { return $this->payment_type === 'CLIENT'; }
}
