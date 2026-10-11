<?php
declare(strict_types=1);
namespace App\Finance\Events;

/**
 * PaymentReceived — fired when a client payment is received.
 * TASK-013B — Finance Application/Service Layer
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * Consumed by: Accounting (for Journal Entry creation)
 * DEC-002: accounting_date = collection_date
 * DEC-006: payment_method + bank_account_id/cash_account_id required
 */
class PaymentReceived
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $invoiceId,
        public readonly float $amount,
        public readonly string $tenantSlug,
        public readonly string $accountingDate = '',
        public readonly string $paymentMethod = '',
        public readonly string|null $bankAccountId = null,
        public readonly string|null $cashAccountId = null,
    ) {}
}
