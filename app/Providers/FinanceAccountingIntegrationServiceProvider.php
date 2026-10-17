<?php
declare(strict_types=1);
namespace App\Providers;

use App\Accounting\AccountingIntegrationService;
use App\Accounting\AccountingService;
use App\Accounting\Listeners\FinanceEventAccountingListener;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * FinanceAccountingIntegrationServiceProvider — registers Finance → Accounting integration.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * Registers AccountingIntegrationService singleton.
 * Registers event listeners for Finance domain events.
 *
 * B-10D FIX: DeferrableProvider removed.
 * Root cause: DeferrableProvider caused boot() to be skipped at app bootstrap,
 * so PaymentReceived/InvoiceIssued/BankTransactionRecorded listeners were never
 * registered with the Event Dispatcher. Event listeners require boot() to run
 * at startup — deferral is incompatible with event registration.
 */
class FinanceAccountingIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccountingIntegrationService::class, function ($app) {
            return new AccountingIntegrationService(
                accountingService: $app->make(AccountingService::class),
            );
        });
    }

    public function boot(): void
    {
        // InvoiceIssued → FinanceEventAccountingListener@handleInvoiceIssued
        Event::listen(
            InvoiceIssued::class,
            [FinanceEventAccountingListener::class, 'handleInvoiceIssued']
        );

        // PaymentReceived → FinanceEventAccountingListener@handlePaymentReceived
        Event::listen(
            PaymentReceived::class,
            [FinanceEventAccountingListener::class, 'handlePaymentReceived']
        );

        // BankTransactionRecorded → FinanceEventAccountingListener@handleBankTransactionRecorded
        Event::listen(
            BankTransactionRecorded::class,
            [FinanceEventAccountingListener::class, 'handleBankTransactionRecorded']
        );
    }
}
