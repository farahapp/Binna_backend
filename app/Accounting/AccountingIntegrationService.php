<?php
declare(strict_types=1);
namespace App\Accounting;

use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use App\Finance\Events\BankTransactionRecorded;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AccountingIntegrationService — Finance → Accounting integration logic.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 *
 * Approved decisions:
 *  DEC-001: Canonical events: InvoiceIssued, PaymentReceived, BankTransactionRecorded
 *  DEC-002: accounting_date sources: invoice_date, collection_date, transaction_date
 *  DEC-003: Exactly one OPEN period required
 *  DEC-004: Exactly one PostingRule required
 *  DEC-005: PostingRule determines both GL accounts for BankTransaction
 *  DEC-006: payment_method + bank_account_id/cash_account_id required for PaymentReceived
 *  DEC-007: QAR only
 *
 * Policy: FAIL → NO JOURNAL ENTRY → throw RuntimeException (queue handles retry)
 */
class AccountingIntegrationService
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * Process InvoiceIssued event → create JournalEntry
     * DEC-001: canonical event name InvoiceIssued
     * DEC-002: accounting_date = invoice_date
     * DEC-003: exactly one OPEN period
     * DEC-004: exactly one PostingRule
     * DEC-007: QAR only
     */
    public function handleInvoiceIssued(InvoiceIssued $event): void
    {
        $tenantSlug = $event->tenantSlug;
        $postingDate = $event->accountingDate;
        $triggerEvent = 'InvoiceIssued';
        $sourceModule = 'Finance';
        $sourceDocumentType = 'Invoice';
        $sourceDocumentId = $event->invoiceId;
        $amount = $event->totalAmount;

        if (empty($postingDate)) {
            throw new RuntimeException(
                "InvoiceIssued: accountingDate is required. Source: {$sourceDocumentId}"
            );
        }

        // Idempotency check
        if ($this->journalEntryExists($tenantSlug, $sourceModule, $sourceDocumentType, $sourceDocumentId)) {
            return; // Already processed — idempotent
        }

        // Resolve PostingRule (exactly one)
        $rule = $this->resolvePostingRule($tenantSlug, $triggerEvent);

        // Resolve AccountingPeriod (exactly one OPEN)
        $period = $this->resolveAccountingPeriod($tenantSlug, $postingDate);

        // Create JournalEntry
        $this->createAndPostJournalEntry(
            tenantSlug: $tenantSlug,
            reference: 'INV-' . substr($sourceDocumentId, 0, 8),
            sourceModule: $sourceModule,
            sourceDocumentId: $sourceDocumentId,
            sourceDocumentType: $sourceDocumentType,
            postingDate: $postingDate,
            periodId: $period->id,
            narration: "Invoice issued - {$sourceDocumentId}",
            debitAccountId: $rule->debit_account_id,
            creditAccountId: $rule->credit_account_id,
            amount: $amount,
        );
    }

    /**
     * Process PaymentReceived event → create JournalEntry
     * DEC-006: payment_method + bank_account_id/cash_account_id required
     */
    public function handlePaymentReceived(PaymentReceived $event): void
    {
        $tenantSlug = $event->tenantSlug;
        $postingDate = $event->accountingDate;
        $triggerEvent = 'PaymentReceived';
        $sourceModule = 'Finance';
        $sourceDocumentType = 'Payment';
        $sourceDocumentId = $event->paymentId;
        $amount = $event->amount;

        if (empty($postingDate)) {
            throw new RuntimeException(
                "PaymentReceived: accountingDate is required. Source: {$sourceDocumentId}"
            );
        }

        // DEC-006: Bank/Cash context required
        if (empty($event->paymentMethod)) {
            throw new RuntimeException(
                "PaymentReceived: payment_method is required for accounting. Source: {$sourceDocumentId}"
            );
        }
        if (in_array($event->paymentMethod, ['BANK_TRANSFER', 'CHEQUE'], true) && empty($event->bankAccountId)) {
            throw new RuntimeException(
                "PaymentReceived: bank_account_id is required for {$event->paymentMethod}. Source: {$sourceDocumentId}"
            );
        }
        if ($event->paymentMethod === 'CASH' && empty($event->cashAccountId)) {
            throw new RuntimeException(
                "PaymentReceived: cash_account_id is required for CASH payment. Source: {$sourceDocumentId}"
            );
        }

        // Idempotency check
        if ($this->journalEntryExists($tenantSlug, $sourceModule, $sourceDocumentType, $sourceDocumentId)) {
            return;
        }

        // Resolve PostingRule
        $rule = $this->resolvePostingRule($tenantSlug, $triggerEvent);

        // Resolve AccountingPeriod
        $period = $this->resolveAccountingPeriod($tenantSlug, $postingDate);

        // Create JournalEntry
        $this->createAndPostJournalEntry(
            tenantSlug: $tenantSlug,
            reference: 'PAY-' . substr($sourceDocumentId, 0, 8),
            sourceModule: $sourceModule,
            sourceDocumentId: $sourceDocumentId,
            sourceDocumentType: $sourceDocumentType,
            postingDate: $postingDate,
            periodId: $period->id,
            narration: "Payment received ({$event->paymentMethod}) - {$sourceDocumentId}",
            debitAccountId: $rule->debit_account_id,
            creditAccountId: $rule->credit_account_id,
            amount: $amount,
        );
    }

    /**
     * Process BankTransactionRecorded event → create JournalEntry
     * DEC-005: PostingRule determines both GL accounts — no invented counter-account
     */
    public function handleBankTransactionRecorded(BankTransactionRecorded $event): void
    {
        $tenantSlug = $event->tenantSlug;
        $postingDate = $event->accountingDate;
        $triggerEvent = 'BankTransactionRecorded';
        $sourceModule = 'Finance';
        $sourceDocumentType = 'BankTransaction';
        $sourceDocumentId = $event->transactionId;
        $amount = $event->amount;

        if (empty($postingDate)) {
            throw new RuntimeException(
                "BankTransactionRecorded: accountingDate is required. Source: {$sourceDocumentId}"
            );
        }

        // Idempotency check
        if ($this->journalEntryExists($tenantSlug, $sourceModule, $sourceDocumentType, $sourceDocumentId)) {
            return;
        }

        // Resolve PostingRule
        $rule = $this->resolvePostingRule($tenantSlug, $triggerEvent);

        // Resolve AccountingPeriod
        $period = $this->resolveAccountingPeriod($tenantSlug, $postingDate);

        $transactionType = $event->transactionType ?: $event->type;

        // Create JournalEntry
        $this->createAndPostJournalEntry(
            tenantSlug: $tenantSlug,
            reference: 'BTX-' . substr($sourceDocumentId, 0, 8),
            sourceModule: $sourceModule,
            sourceDocumentId: $sourceDocumentId,
            sourceDocumentType: $sourceDocumentType,
            postingDate: $postingDate,
            periodId: $period->id,
            narration: "Bank transaction ({$transactionType}) - {$sourceDocumentId}",
            debitAccountId: $rule->debit_account_id,
            creditAccountId: $rule->credit_account_id,
            amount: $amount,
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve exactly one active PostingRule for the given trigger event.
     * DEC-004: zero matches = failure, multiple matches = failure.
     */
    private function resolvePostingRule(string $tenantSlug, string $triggerEvent): PostingRule
    {
        $rules = PostingRule::where('trigger_event', $triggerEvent)
            ->where('is_active', true)
            ->where('tenant_slug', $tenantSlug)
            ->get();

        if ($rules->count() === 0) {
            throw new RuntimeException(
                "No active PostingRule found for trigger_event='{$triggerEvent}' in tenant='{$tenantSlug}'. " .
                "Integration failure: zero PostingRule matches."
            );
        }

        if ($rules->count() > 1) {
            throw new RuntimeException(
                "Multiple active PostingRules found for trigger_event='{$triggerEvent}' in tenant='{$tenantSlug}'. " .
                "Integration failure: ambiguous PostingRule — exactly one required."
            );
        }

        return $rules->first();
    }

    /**
     * Resolve exactly one OPEN AccountingPeriod containing the posting date.
     * DEC-003: no period = failure, closed = failure, multiple = failure.
     */
    private function resolveAccountingPeriod(string $tenantSlug, string $postingDate): AccountingPeriod
    {
        $periods = AccountingPeriod::where('tenant_slug', $tenantSlug)
            ->where('status', 'OPEN')
            ->where('start_date', '<=', $postingDate)
            ->where('end_date', '>=', $postingDate)
            ->get();

        if ($periods->count() === 0) {
            // Check if a period exists but is closed
            $anyPeriod = AccountingPeriod::where('tenant_slug', $tenantSlug)
                ->where('start_date', '<=', $postingDate)
                ->where('end_date', '>=', $postingDate)
                ->first();

            if ($anyPeriod) {
                throw new RuntimeException(
                    "AccountingPeriod containing date='{$postingDate}' exists but is {$anyPeriod->status} in tenant='{$tenantSlug}'. " .
                    "Integration failure: period is not OPEN."
                );
            }

            throw new RuntimeException(
                "No AccountingPeriod found containing date='{$postingDate}' in tenant='{$tenantSlug}'. " .
                "Integration failure: no matching period."
            );
        }

        if ($periods->count() > 1) {
            throw new RuntimeException(
                "Multiple AccountingPeriods found containing date='{$postingDate}' in tenant='{$tenantSlug}'. " .
                "Integration failure: ambiguous period — exactly one required."
            );
        }

        return $periods->first();
    }

    /**
     * Idempotency check: has a JournalEntry already been created for this source document?
     */
    private function journalEntryExists(
        string $tenantSlug,
        string $sourceModule,
        string $sourceDocumentType,
        string $sourceDocumentId,
    ): bool {
        return JournalEntry::where('tenant_slug', $tenantSlug)
            ->where('source_module', $sourceModule)
            ->where('source_document_type', $sourceDocumentType)
            ->where('source_document_id', $sourceDocumentId)
            ->exists();
    }

    /**
     * Create a DRAFT JournalEntry with two balanced lines, then post it.
     */
    private function createAndPostJournalEntry(
        string $tenantSlug,
        string $reference,
        string $sourceModule,
        string $sourceDocumentId,
        string $sourceDocumentType,
        string $postingDate,
        string $periodId,
        string $narration,
        string $debitAccountId,
        string $creditAccountId,
        float $amount,
    ): JournalEntry {
        return DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $reference, $sourceModule, $sourceDocumentId, $sourceDocumentType,
            $postingDate, $periodId, $narration, $debitAccountId, $creditAccountId, $amount
        ) {
            $entry = $this->accountingService->createJournalEntry(
                tenantSlug: $tenantSlug,
                reference: $reference,
                postingDate: $postingDate,
                periodId: $periodId,
                lines: [
                    ['account_id' => $debitAccountId,  'debit' => $amount, 'credit' => 0.0],
                    ['account_id' => $creditAccountId, 'debit' => 0.0,    'credit' => $amount],
                ],
                sourceModule: $sourceModule,
                sourceDocumentId: $sourceDocumentId,
                sourceDocumentType: $sourceDocumentType,
                narration: $narration,
            );

            $this->accountingService->postJournalEntry(
                tenantSlug: $tenantSlug,
                entryId: $entry->id,
            );

            return $entry;
        });
    }
}
