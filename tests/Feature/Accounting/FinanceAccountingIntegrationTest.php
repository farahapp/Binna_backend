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

    // =========================================================================
    // DEC-B10A: Reference format uses full UUID — no collision with UUID v7
    // =========================================================================

    /**
     * @test
     * DEC-B10A-01: InvoiceIssued reference = 'INV-{full_uuid}'
     */
    public function dec_b10a_01_invoice_issued_reference_contains_full_source_document_id(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $invoiceId = Str::uuid()->toString();
        $event = $this->makeInvoiceIssuedEvent(invoiceId: $invoiceId);
        $this->integrationService->handleInvoiceIssued($event);

        $entry = JournalEntry::where('source_document_id', $invoiceId)->first();
        $this->assertNotNull($entry);
        $this->assertSame('INV-' . $invoiceId, $entry->reference,
            'DEC-B10A: reference must be INV-{full_uuid}');
        $this->assertStringNotContainsString(
            'INV-' . substr(str_replace('-', '', $invoiceId), 0, 8),
            $entry->reference === 'INV-' . $invoiceId ? '' : $entry->reference,
            'DEC-B10A: reference must NOT use 8-char substr'
        );
    }

    /**
     * @test
     * DEC-B10A-02: PaymentReceived reference = 'PAY-{full_uuid}'
     */
    public function dec_b10a_02_payment_received_reference_contains_full_source_document_id(): void
    {
        $this->createPostingRule('PaymentReceived');

        $paymentId = Str::uuid()->toString();
        $cashAccountId = Str::uuid()->toString();
        $event = $this->makePaymentReceivedEvent(
            paymentId:     $paymentId,
            paymentMethod: 'CASH',
            cashAccountId: $cashAccountId,
        );
        $this->integrationService->handlePaymentReceived($event);

        $entry = JournalEntry::where('source_document_id', $paymentId)->first();
        $this->assertNotNull($entry);
        $this->assertSame('PAY-' . $paymentId, $entry->reference,
            'DEC-B10A: reference must be PAY-{full_uuid}');
    }

    /**
     * @test
     * DEC-B10A-03: BankTransactionRecorded reference = 'BTX-{full_uuid}'
     */
    public function dec_b10a_03_bank_transaction_reference_contains_full_source_document_id(): void
    {
        $this->createPostingRule('BankTransactionRecorded');

        $transactionId = Str::uuid()->toString();
        $event = $this->makeBankTransactionRecordedEvent(transactionId: $transactionId);
        $this->integrationService->handleBankTransactionRecorded($event);

        $entry = JournalEntry::where('source_document_id', $transactionId)->first();
        $this->assertNotNull($entry);
        $this->assertSame('BTX-' . $transactionId, $entry->reference,
            'DEC-B10A: reference must be BTX-{full_uuid}');
    }

    /**
     * @test
     * DEC-B10A-04: Three InvoiceIssued events with different UUIDs → three unique references
     */
    public function dec_b10a_04_three_invoice_events_produce_three_unique_references(): void
    {
        $this->createPostingRule('InvoiceIssued');

        $ids = [Str::uuid()->toString(), Str::uuid()->toString(), Str::uuid()->toString()];

        foreach ($ids as $id) {
            $event = $this->makeInvoiceIssuedEvent(invoiceId: $id);
            $this->integrationService->handleInvoiceIssued($event);
        }

        $references = JournalEntry::whereIn('source_document_id', $ids)
            ->pluck('reference')
            ->toArray();

        $this->assertCount(3, $references, 'DEC-B10A: must create 3 journal entries');
        $this->assertCount(3, array_unique($references),
            'DEC-B10A: all 3 references must be unique');

        foreach ($ids as $id) {
            $this->assertContains('INV-' . $id, $references,
                "DEC-B10A: reference for {$id} must be INV-{$id}");
        }
    }

    /**
     * @test
     * DEC-B10A-05: Three PaymentReceived events → three unique PAY- references
     */
    public function dec_b10a_05_three_payment_events_produce_three_unique_references(): void
    {
        $this->createPostingRule('PaymentReceived');

        $ids = [Str::uuid()->toString(), Str::uuid()->toString(), Str::uuid()->toString()];

        foreach ($ids as $id) {
            $event = $this->makePaymentReceivedEvent(
                paymentId:     $id,
                paymentMethod: 'BANK_TRANSFER',
                bankAccountId: Str::uuid()->toString(),
            );
            $this->integrationService->handlePaymentReceived($event);
        }

        $references = JournalEntry::whereIn('source_document_id', $ids)
            ->pluck('reference')
            ->toArray();

        $this->assertCount(3, $references, 'DEC-B10A: must create 3 journal entries');
        $this->assertCount(3, array_unique($references),
            'DEC-B10A: all 3 PAY- references must be unique');
    }

    /**
     * @test
     * DEC-B10A-06: Two invoices sharing same 8-char UUID prefix → two distinct journal entries
     * This is the exact collision scenario that BUG-B10-001 triggered.
     * UUID v7 generates IDs like: 01a13480-2153-... and 01a13480-bfcd-...
     * Both share prefix '01a13480' — old code would collide on 'INV-01a13480'.
     */
    public function dec_b10a_06_two_uuids_sharing_8char_prefix_produce_distinct_references(): void
    {
        $this->createPostingRule('InvoiceIssued');

        // Craft two UUIDs that share the same first 8 hex chars (simulating UUID v7 same-millisecond)
        $sharedPrefix = '01a13480';
        $uuid1 = $sharedPrefix . '-2153-72ed-b342-921cd912025f';
        $uuid2 = $sharedPrefix . '-bfcd-7255-8af9-4b10a89adbb5';

        // Confirm they share the 8-char prefix (the old collision condition)
        $this->assertSame(
            substr(str_replace('-', '', $uuid1), 0, 8),
            substr(str_replace('-', '', $uuid2), 0, 8),
            'Test setup: both UUIDs must share same 8-char prefix'
        );

        // With old code: both would generate 'INV-01a13480' → collision
        // With new code: 'INV-01a13480-2153-...' vs 'INV-01a13480-bfcd-...' → unique
        $event1 = $this->makeInvoiceIssuedEvent(invoiceId: $uuid1, amount: 1000.0);
        $event2 = $this->makeInvoiceIssuedEvent(invoiceId: $uuid2, amount: 2000.0);

        // Both must succeed without exception
        $this->integrationService->handleInvoiceIssued($event1);
        $this->integrationService->handleInvoiceIssued($event2);

        $entry1 = JournalEntry::where('source_document_id', $uuid1)->first();
        $entry2 = JournalEntry::where('source_document_id', $uuid2)->first();

        $this->assertNotNull($entry1, 'DEC-B10A: first entry must be created');
        $this->assertNotNull($entry2, 'DEC-B10A: second entry must be created');
        $this->assertNotSame($entry1->reference, $entry2->reference,
            'DEC-B10A: references must differ even when UUIDs share 8-char prefix');
        $this->assertSame('INV-' . $uuid1, $entry1->reference);
        $this->assertSame('INV-' . $uuid2, $entry2->reference);
    }

    // =========================================================================
    // B-10D: Listener Registration Tests
    // Proves that FinanceAccountingIntegrationServiceProvider.boot() runs at
    // app bootstrap (not deferred) and registers all three Finance event listeners.
    // =========================================================================

    /**
     * Helper: check if FinanceEventAccountingListener is registered for a given event.
     * Uses getRawListeners() to inspect the raw registration (before Closure wrapping).
     */
    private function assertFinanceListenerRegistered(string $eventClass): void
    {
        // getRawListeners() returns the raw array before Closure wrapping
        $rawListeners = app('events')->getRawListeners();

        $this->assertArrayHasKey(
            $eventClass,
            $rawListeners,
            "B-10D: No listeners registered for {$eventClass}. " .
            'FinanceAccountingIntegrationServiceProvider.boot() must run at startup. ' .
            'If this fails, DeferrableProvider is still active or provider is not registered.'
        );

        $found = false;
        foreach ($rawListeners[$eventClass] as $listener) {
            // Raw listeners can be: array [class, method], string 'Class@method', or Closure
            if (is_array($listener)) {
                $listenerStr = implode('@', $listener);
            } elseif (is_string($listener)) {
                $listenerStr = $listener;
            } else {
                // Closure — inspect via reflection
                try {
                    $ref = new \ReflectionFunction($listener);
                    $listenerStr = $ref->getStaticVariables()['listener'] ?? '';
                    if (is_array($listenerStr)) {
                        $listenerStr = implode('@', $listenerStr);
                    }
                } catch (\Throwable) {
                    $listenerStr = '';
                }
            }
            if (str_contains((string) $listenerStr, 'FinanceEventAccountingListener')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            "B-10D: FinanceEventAccountingListener not found in raw listeners for {$eventClass}. " .
            'Registered: ' . json_encode(array_map(
                fn($l) => is_array($l) ? implode('@', $l) : (is_string($l) ? $l : 'Closure'),
                $rawListeners[$eventClass]
            ))
        );
    }

    /**
     * B-10D-01: PaymentReceived listener is registered with the Event Dispatcher.
     * Fails if FinanceAccountingIntegrationServiceProvider is DeferrableProvider
     * and boot() was not called at startup.
     *
     * @test
     */
    public function b10d_01_payment_received_listener_is_registered(): void
    {
        $this->assertFinanceListenerRegistered(\App\Finance\Events\PaymentReceived::class);
    }

    /**
     * B-10D-02: InvoiceIssued listener is registered with the Event Dispatcher.
     *
     * @test
     */
    public function b10d_02_invoice_issued_listener_is_registered(): void
    {
        $this->assertFinanceListenerRegistered(\App\Finance\Events\InvoiceIssued::class);
    }

    /**
     * B-10D-03: BankTransactionRecorded listener is registered with the Event Dispatcher.
     *
     * @test
     */
    public function b10d_03_bank_transaction_recorded_listener_is_registered(): void
    {
        $this->assertFinanceListenerRegistered(\App\Finance\Events\BankTransactionRecorded::class);
    }

    /**
     * B-10D-04: All three Finance event listeners are registered in a single assertion.
     * Canonical registration test — use this as the primary B-10D verification.
     *
     * @test
     */
    public function b10d_04_all_finance_accounting_listeners_are_registered(): void
    {
        $this->assertFinanceListenerRegistered(\App\Finance\Events\PaymentReceived::class);
        $this->assertFinanceListenerRegistered(\App\Finance\Events\InvoiceIssued::class);
        $this->assertFinanceListenerRegistered(\App\Finance\Events\BankTransactionRecorded::class);
    }
}
