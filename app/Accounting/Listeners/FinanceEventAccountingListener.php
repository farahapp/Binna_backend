<?php
declare(strict_types=1);
namespace App\Accounting\Listeners;

use App\Accounting\AccountingIntegrationService;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * FinanceEventAccountingListener — Listens to Finance domain events and creates Journal Entries.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * DEC-013: Finance emits events; Accounting owns PostingRules and journals.
 * NEW-001: Tenant context restored inside async job.
 * Implements ShouldQueue for async processing.
 */
class FinanceEventAccountingListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 60; // seconds

    public function __construct(
        private readonly AccountingIntegrationService $integrationService,
    ) {}

    /**
     * Handle InvoiceIssued event.
     */
    public function handleInvoiceIssued(InvoiceIssued $event): void
    {
        $this->integrationService->handleInvoiceIssued($event);
    }

    /**
     * Handle PaymentReceived event.
     */
    public function handlePaymentReceived(PaymentReceived $event): void
    {
        $this->integrationService->handlePaymentReceived($event);
    }

    /**
     * Handle BankTransactionRecorded event.
     */
    public function handleBankTransactionRecorded(BankTransactionRecorded $event): void
    {
        $this->integrationService->handleBankTransactionRecorded($event);
    }

    /**
     * Handle a job failure.
     * Laravel calls failed($event, $exception) for queued listeners.
     * Signature must accept the original event as first argument.
     */
    public function failed(mixed $event, \Throwable $exception): void
    {
        // Failure is observable via failed_jobs table (Laravel default behavior)
        // No partial journal entry is created — the transaction was rolled back
        report($exception);
    }
}
