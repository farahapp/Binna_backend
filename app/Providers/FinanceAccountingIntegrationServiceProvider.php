<?php
declare(strict_types=1);
namespace App\Providers;

use App\Accounting\AccountingIntegrationService;
use App\Accounting\AccountingService;
use App\Accounting\Listeners\FinanceEventAccountingListener;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * FinanceAccountingIntegrationServiceProvider — registers Finance → Accounting integration.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * Registers AccountingIntegrationService singleton.
 * Registers event listeners for Finance domain events.
 *
 * Implements DeferrableProvider so this provider is only loaded when
 * AccountingIntegrationService is actually resolved from the container,
 * preventing PHPUnit bootstrap hang in unrelated test suites.
 */
class FinanceAccountingIntegrationServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Declare the services provided by this provider.
     * Required by DeferrableProvider — tells Laravel when to load this provider.
     */
    public function provides(): array
    {
        return [AccountingIntegrationService::class];
    }

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
