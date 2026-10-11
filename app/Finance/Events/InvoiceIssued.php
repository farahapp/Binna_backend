<?php
declare(strict_types=1);
namespace App\Finance\Events;

/**
 * InvoiceIssued — fired when a Finance invoice is issued.
 * TASK-013B — Finance Application/Service Layer
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * Consumed by: Accounting (for Journal Entry creation)
 * DEC-002: accounting_date = invoice_date
 */
class InvoiceIssued
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $clientId,
        public readonly string|null $projectId,
        public readonly float $totalAmount,
        public readonly string $tenantSlug,
        public readonly string $accountingDate = '',
    ) {}
}
