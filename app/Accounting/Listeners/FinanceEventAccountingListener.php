<?php
declare(strict_types=1);
namespace App\Accounting\Listeners;

use App\Accounting\AccountingIntegrationService;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use App\Foundation\SwitchToTenantSchemaForJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * FinanceEventAccountingListener — Listens to Finance domain events and creates Journal Entries.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * DEC-013: Finance emits events; Accounting owns PostingRules and journals.
 * NEW-001: Tenant context restored inside async job via SwitchToTenantSchemaForJob middleware.
 * OI-QUEUE-001: Queue tenant schema isolation — 2026-10-16.
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
     * Job middleware: switch the 'tenant' connection to the correct per-tenant
     * schema before the job runs, and restore it afterwards.
     *
     * Laravel calls middleware($event) on queued listeners, passing the
     * serialised event as the first argument. The tenantSlug is extracted
     * from the event payload; schema_name is resolved from the database by
     * SwitchToTenantSchemaForJob — never derived from the payload directly (OD-001).
     *
     * @param  mixed $event  The Finance domain event (InvoiceIssued | PaymentReceived | BankTransactionRecorded)
     */
    public function middleware(mixed $event): array
    {
        $tenantSlug = $event->tenantSlug ?? '';

        // Return a single closure-based middleware so tenantSlug is captured
        // at dispatch time and passed directly to SwitchToTenantSchemaForJob.
        return [
            function (mixed $job, callable $next) use ($tenantSlug): void {
                app(SwitchToTenantSchemaForJob::class)->handle($job, $next, $tenantSlug);
            },
        ];
    }

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
