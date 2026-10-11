<?php
declare(strict_types=1);
namespace App\Finance\Events;

/**
 * BankTransactionRecorded — fired when a bank transaction is recorded.
 * TASK-013B — Finance Application/Service Layer
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * Consumed by: Accounting (for Journal Entry creation)
 * DEC-002: accounting_date = transaction_date
 * DEC-005: PostingRule determines both GL accounts
 */
class BankTransactionRecorded
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $bankAccountId,
        public readonly string $type,
        public readonly float $amount,
        public readonly string $tenantSlug,
        public readonly string $accountingDate = '',
        public readonly string $transactionType = '',
    ) {}
}
