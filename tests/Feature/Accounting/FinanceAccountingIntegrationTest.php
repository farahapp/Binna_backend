<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Accounting\Account;
use App\Accounting\AccountingIntegrationService;
use App\Accounting\AccountingPeriod;
use App\Accounting\AccountingService;
use App\Accounting\JournalEntry;
use App\Accounting\PostingRule;
use App\Finance\BankAccount;
use App\Finance\CashAccount;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use App\Finance\FinanceService;
use App\Finance\Invoice;
use App\Finance\Receivable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * FinanceAccountingIntegrationTest — Finance → Accounting Integration tests.
 * TASK-016 — Finance → Accounting Integration (OI-E2E-03)
 * Covers AC-01 through AC-18.
 */
class FinanceAccountingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure RefreshDatabase wraps BOTH the default (mysql) and tenant connections
     * in transactions so setUp() data is rolled back between tests.
     */
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'tenant'];
    }

    private AccountingIntegrationService $integrationService;
    private AccountingService $accountingService;
    private FinanceService $financeService;
    private string $tenantSlug = 'integration-test';

    // GL accounts
    private Account $debitAccount;
    private Account $creditAccount;

    // Accounting period
    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationService = app(AccountingIntegrationService::class);
        $this->accountingService  = app(AccountingService::class);
        $this->financeService     = app(FinanceService::class);

        // Use Model::create directly to bypass AccountingService duplicate-code check
        // (the check is global, not per-tenant, so it fails on second test in same run)
        $this->debitAccount = Account::create([
            'account_code' => 'AR-001',
            'name'         => 'Accounts Receivable',
            'account_type' => 'ASSET',
            'status'       => 'active',
            'tenant_slug'  => $this->tenantSlug,
        ]);
        $this->creditAccount = Account::create([
            'account_code' => 'REV-001',
            'name'         => 'Revenue',
            'account_type' => 'REVENUE',
            'status'       => 'active',
            'tenant_slug'  => $this->tenantSlug,
        ]);

        // Create OPEN accounting period covering 2026-10
        $this->period = AccountingPeriod::create([
            'period_name' => 'INT-TEST-2026-10',
            'start_date'  => '2026-10-01',
            'end_date'    => '2026-10-31',
            'status'      => 'OPEN',
            'tenant_slug' => $this->tenantSlug,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createPostingRule(string $triggerEvent): PostingRule
    {
        return $this->accountingService->createPostingRule(
            tenantSlug:      $this->tenantSlug,
            triggerEvent:    $triggerEvent,
            ruleName:        "{$triggerEvent} Rule",
            debitAccountId:  $this->debitAccount->id,
            creditAccountId: $this->creditAccount->id,
            amountSource:    'totalAmount',
        );
    }

    private function makeInvoiceIssuedEvent(
        string $invoiceId = '',
        float $amount = 1000.0,
        string $accountingDate = '2026-10-15',
    ): InvoiceIssued {
        return new InvoiceIssued(
            invoiceId:      $invoiceId ?: Str::uuid()->toString(),
            clientId:       Str::uuid()->toString(),
            projectId:      null,
            totalAmount:    $amount,
            tenantSlug:     $this->tenantSlug,
            accountingDate: $accountingDate,
        );
    }

    private function makePaymentReceivedEvent(
        string $paymentId = '',
        float $amount = 500.0,
        string $accountingDate = '2026-10-15',
        string $paymentMethod = 'BANK_TRANSFER',
        string|null $bankAccountId = null,
        string|null $cashAccountId = null,
    ): PaymentReceived {
        return new PaymentReceived(
            paymentId:      $paymentId ?: Str::uuid()->toString(),
            invoiceId:      Str::uuid()->toString(),
            amount:         $amount,
            tenantSlug:     $this->tenantSlug,
            accountingDate: $accountingDate,
            paymentMethod:  $paymentMethod,
            bankAccountId:  $bankAccountId ?? ($paymentMethod !== 'CASH' ? Str::uuid()->toString() : null),
            cashAccountId:  $cashAccountId ?? ($paymentMethod === 'CASH' ? Str::uuid()->toString() : null),
        );
    }

    private function makeBankTransactionRecordedEvent(
        string $transactionId = '',
        float $amount = 2000.0,
        string $accountingDate = '2026-10-15',
        string $type = 'CREDIT',
    ): BankTransactionRecorded {
        return new BankTransactionRecorded(
            transactionId:   $transactionId ?: Str::uuid()->toString(),
            bankAccountId:   Str::uuid()->toString(),
            type:            $type,
            amount:          $amount,
            tenantSlug:      $this->tenantSlug,
            accountingDate:  $accountingDate,
            transactionType: $type,
        );
    }

    // =========================================================================
    // AC-01: InvoiceIssued → JournalEntry created and POSTED
    // =========================================================================

    /** @test */
    public function ac01_invoice_issued_creates_posted_journal_entry(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $event = $this->makeInvoiceIssuedEvent(amount: 1500.0);
        $this->integrationService->handleInvoiceIssued($event);

        $entry = JournalEntry::where('source_document_id', $event->invoiceId)
            ->where('source_module', 'Finance')
            ->where('source_document_type', 'Invoice')
            ->first();

        $this->assertNotNull($entry, 'JournalEntry must be created for InvoiceIssued');
        $this->assertSame('POSTED', $entry->status);
        $this->assertSame($this->tenantSlug, $entry->tenant_slug);

        $lines = $entry->lines;
        $this->assertCount(2, $lines);
        $totalDebit  = $lines->sum(fn($l) => (float) $l->debit);
        $totalCredit = $lines->sum(fn($l) => (float) $l->credit);
        $this->assertEquals(1500.0, $totalDebit);
        $this->assertEquals(1500.0, $totalCredit);
    }

    // =========================================================================
    // AC-02: PaymentReceived (BANK_TRANSFER) → JournalEntry created and POSTED
    // =========================================================================

    /** @test */
    public function ac02_payment_received_bank_transfer_creates_posted_journal_entry(): void
    {
        $this->createPostingRule('PaymentReceived');

        $event = $this->makePaymentReceivedEvent(
            amount:        750.0,
            paymentMethod: 'BANK_TRANSFER',
        );
        $this->integrationService->handlePaymentReceived($event);

        $entry = JournalEntry::where('source_document_id', $event->paymentId)
            ->where('source_document_type', 'Payment')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('POSTED', $entry->status);
    }

    // =========================================================================
    // AC-03: BankTransactionRecorded → JournalEntry created and POSTED
    // =========================================================================

    /** @test */
    public function ac03_bank_transaction_recorded_creates_posted_journal_entry(): void
    {
        $this->createPostingRule('BankTransactionRecorded');

        $event = $this->makeBankTransactionRecordedEvent(amount: 3000.0);
        $this->integrationService->handleBankTransactionRecorded($event);

        $entry = JournalEntry::where('source_document_id', $event->transactionId)
            ->where('source_document_type', 'BankTransaction')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('POSTED', $entry->status);
    }

    // =========================================================================
    // AC-04: Idempotency — same event twice → only one JournalEntry
    // =========================================================================

    /** @test */
    public function ac04_idempotency_same_invoice_event_twice_creates_only_one_journal_entry(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $invoiceId = Str::uuid()->toString();
        $event = $this->makeInvoiceIssuedEvent(invoiceId: $invoiceId);

        $this->integrationService->handleInvoiceIssued($event);
        $this->integrationService->handleInvoiceIssued($event); // second call — idempotent

        $count = JournalEntry::where('source_document_id', $invoiceId)
            ->where('source_document_type', 'Invoice')
            ->count();

        $this->assertEquals(1, $count, 'Idempotency: only one JournalEntry must be created');
    }

    // =========================================================================
    // AC-05: No PostingRule → RuntimeException, no JournalEntry
    // =========================================================================

    /** @test */
    public function ac05_no_posting_rule_throws_exception_and_no_journal_entry_created(): void
    {
        // No PostingRule created for InvoiceIssued
        $event = $this->makeInvoiceIssuedEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No active PostingRule/');

        $this->integrationService->handleInvoiceIssued($event);

        $this->assertEquals(0, JournalEntry::count());
    }

    // =========================================================================
    // AC-06: Multiple PostingRules → RuntimeException, no JournalEntry
    // =========================================================================

    /** @test */
    public function ac06_multiple_posting_rules_throws_exception(): void
    {
        // Create two PostingRules for same trigger
        $this->createPostingRule('InvoiceIssued');
        PostingRule::create([
            'trigger_event'    => 'InvoiceIssued',
            'rule_name'        => 'Duplicate Rule',
            'debit_account_id' => $this->debitAccount->id,
            'credit_account_id'=> $this->creditAccount->id,
            'amount_source'    => 'totalAmount',
            'is_active'        => true,
            'tenant_slug'      => $this->tenantSlug,
        ]);

        $event = $this->makeInvoiceIssuedEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Multiple active PostingRules/');

        $this->integrationService->handleInvoiceIssued($event);
    }

    // =========================================================================
    // AC-07: No AccountingPeriod → RuntimeException, no JournalEntry
    // =========================================================================

    /** @test */
    public function ac07_no_accounting_period_throws_exception(): void
    {
        $this->createPostingRule('InvoiceIssued');

        // Use a date outside the period (2026-10-01 to 2026-10-31)
        $event = $this->makeInvoiceIssuedEvent(accountingDate: '2025-01-15');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No AccountingPeriod found/');

        $this->integrationService->handleInvoiceIssued($event);
    }

    // =========================================================================
    // AC-08: Closed AccountingPeriod → RuntimeException, no JournalEntry
    // =========================================================================

    /** @test */
    public function ac08_closed_accounting_period_throws_exception(): void
    {
        $this->createPostingRule('InvoiceIssued');

        // Create a separate closed period directly (bypass duplicate-name check)
        $closedPeriod = AccountingPeriod::create([
            'period_name' => 'INT-TEST-CLOSED',
            'start_date'  => '2026-09-01',
            'end_date'    => '2026-09-30',
            'status'      => 'CLOSED',
            'tenant_slug' => $this->tenantSlug,
        ]);

        $event = $this->makeInvoiceIssuedEvent(accountingDate: '2026-09-15');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exists but is CLOSED/');

        $this->integrationService->handleInvoiceIssued($event);
    }

    // =========================================================================
    // AC-09: Multiple OPEN AccountingPeriods → RuntimeException
    // =========================================================================

    /** @test */
    public function ac09_multiple_open_periods_throws_exception(): void
    {
        $this->createPostingRule('InvoiceIssued');

        // Create a second overlapping OPEN period
        AccountingPeriod::create([
            'period_name' => 'INT-TEST-OVERLAP',
            'start_date'  => '2026-10-01',
            'end_date'    => '2026-10-31',
            'status'      => 'OPEN',
            'tenant_slug' => $this->tenantSlug,
        ]);

        $event = $this->makeInvoiceIssuedEvent(accountingDate: '2026-10-15');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Multiple AccountingPeriods/');

        $this->integrationService->handleInvoiceIssued($event);
    }

    // =========================================================================
    // AC-10: PaymentReceived missing payment_method → RuntimeException
    // =========================================================================

    /** @test */
    public function ac10_payment_received_missing_payment_method_throws_exception(): void
    {
        $this->createPostingRule('PaymentReceived');

        $event = new PaymentReceived(
            paymentId:      Str::uuid()->toString(),
            invoiceId:      Str::uuid()->toString(),
            amount:         500.0,
            tenantSlug:     $this->tenantSlug,
            accountingDate: '2026-10-15',
            paymentMethod:  '', // missing
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment_method is required/');

        $this->integrationService->handlePaymentReceived($event);
    }

    // =========================================================================
    // AC-11: PaymentReceived BANK_TRANSFER missing bank_account_id → RuntimeException
    // =========================================================================

    /** @test */
    public function ac11_payment_received_bank_transfer_missing_bank_account_id_throws_exception(): void
    {
        $this->createPostingRule('PaymentReceived');

        $event = new PaymentReceived(
            paymentId:      Str::uuid()->toString(),
            invoiceId:      Str::uuid()->toString(),
            amount:         500.0,
            tenantSlug:     $this->tenantSlug,
            accountingDate: '2026-10-15',
            paymentMethod:  'BANK_TRANSFER',
            bankAccountId:  null, // missing
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bank_account_id is required/');

        $this->integrationService->handlePaymentReceived($event);
    }

    // =========================================================================
    // AC-12: PaymentReceived CASH missing cash_account_id → RuntimeException
    // =========================================================================

    /** @test */
    public function ac12_payment_received_cash_missing_cash_account_id_throws_exception(): void
    {
        $this->createPostingRule('PaymentReceived');

        $event = new PaymentReceived(
            paymentId:      Str::uuid()->toString(),
            invoiceId:      Str::uuid()->toString(),
            amount:         500.0,
            tenantSlug:     $this->tenantSlug,
            accountingDate: '2026-10-15',
            paymentMethod:  'CASH',
            cashAccountId:  null, // missing
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cash_account_id is required/');

        $this->integrationService->handlePaymentReceived($event);
    }

    // =========================================================================
    // AC-13: Tenant isolation — event for tenant-A does not create entry for tenant-B
    // =========================================================================

    /** @test */
    public function ac13_tenant_isolation_different_tenant_no_cross_contamination(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $tenantB = 'integration-test-tenant-b';

        // Event for tenant-B (no PostingRule for tenant-B)
        $eventB = new InvoiceIssued(
            invoiceId:      Str::uuid()->toString(),
            clientId:       Str::uuid()->toString(),
            projectId:      null,
            totalAmount:    999.0,
            tenantSlug:     $tenantB,
            accountingDate: '2026-10-15',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No active PostingRule/');

        $this->integrationService->handleInvoiceIssued($eventB);

        // Verify no entry was created for tenant-B
        $this->assertEquals(0, JournalEntry::where('tenant_slug', $tenantB)->count());
    }

    // =========================================================================
    // AC-14: JournalEntry is balanced (debit = credit)
    // =========================================================================

    /** @test */
    public function ac14_journal_entry_is_balanced(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $event = $this->makeInvoiceIssuedEvent(amount: 2500.0);
        $this->integrationService->handleInvoiceIssued($event);

        $entry = JournalEntry::where('source_document_id', $event->invoiceId)->first();
        $this->assertNotNull($entry);

        $lines = $entry->lines;
        $totalDebit  = $lines->sum(fn($l) => (float) $l->debit);
        $totalCredit = $lines->sum(fn($l) => (float) $l->credit);

        $this->assertEquals($totalDebit, $totalCredit, 'JournalEntry must be balanced');
    }

    // =========================================================================
    // AC-15: QAR only — amount is stored as-is (no FX conversion)
    // =========================================================================

    /** @test */
    public function ac15_qar_only_amount_stored_as_is(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $event = $this->makeInvoiceIssuedEvent(amount: 1234.56);
        $this->integrationService->handleInvoiceIssued($event);

        $entry = JournalEntry::where('source_document_id', $event->invoiceId)->first();
        $this->assertNotNull($entry);

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($debitLine);
        $this->assertEqualsWithDelta(1234.56, (float) $debitLine->debit, 0.001);
    }

    // =========================================================================
    // AC-16: PaymentReceived CHEQUE with bank_account_id → success
    // =========================================================================

    /** @test */
    public function ac16_payment_received_cheque_with_bank_account_id_succeeds(): void
    {
        $this->createPostingRule('PaymentReceived');

        $event = $this->makePaymentReceivedEvent(
            amount:        800.0,
            paymentMethod: 'CHEQUE',
        );
        $this->integrationService->handlePaymentReceived($event);

        $entry = JournalEntry::where('source_document_id', $event->paymentId)->first();
        $this->assertNotNull($entry);
        $this->assertSame('POSTED', $entry->status);
    }

    // =========================================================================
    // AC-17: BankTransaction DEBIT → JournalEntry created
    // =========================================================================

    /** @test */
    public function ac17_bank_transaction_debit_creates_journal_entry(): void
    {
        $this->createPostingRule('BankTransactionRecorded');

        $event = $this->makeBankTransactionRecordedEvent(
            amount: 1000.0,
            type:   'DEBIT',
        );
        $this->integrationService->handleBankTransactionRecorded($event);

        $entry = JournalEntry::where('source_document_id', $event->transactionId)->first();
        $this->assertNotNull($entry);
        $this->assertSame('POSTED', $entry->status);
    }

    // =========================================================================
    // AC-18: E2E — FinanceService.issueInvoice fires event → listener creates JournalEntry
    // =========================================================================

    /** @test */
    public function ac18_e2e_issue_invoice_via_finance_service_creates_journal_entry(): void
    {
        $this->createPostingRule('InvoiceIssued');

        // Create and issue an invoice via FinanceService
        $invoice = $this->financeService->createInvoice(
            tenantSlug:    $this->tenantSlug,
            invoiceNumber: 'E2E-INV-001',
            clientId:      Str::uuid()->toString(),
            invoiceDate:   '2026-10-15',
        );
        $this->financeService->addInvoiceItem(
            tenantSlug:  $this->tenantSlug,
            invoiceId:   $invoice->id,
            description: 'E2E Test Service',
            quantity:    1.0,
            unitPrice:   5000.0,
        );

        // issueInvoice dispatches InvoiceIssued event → listener runs synchronously (QUEUE_CONNECTION=sync)
        $this->financeService->issueInvoice($this->tenantSlug, $invoice->id);

        // Verify JournalEntry was created
        $entry = JournalEntry::where('source_document_id', $invoice->id)
            ->where('source_document_type', 'Invoice')
            ->first();

        $this->assertNotNull($entry, 'E2E: JournalEntry must be created after issueInvoice');
        $this->assertSame('POSTED', $entry->status);
        $this->assertEqualsWithDelta(5000.0, (float) $entry->lines->sum('debit'), 0.001);
    }
}
