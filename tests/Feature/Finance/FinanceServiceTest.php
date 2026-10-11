<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Finance\BankAccount;
use App\Finance\BankTransaction;
use App\Finance\CashAccount;
use App\Finance\Collection;
use App\Finance\FinanceService;
use App\Finance\Invoice;
use App\Finance\Receivable;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * FinanceServiceTest — Feature tests for Finance Application/Service Layer.
 * TASK-013B — Finance Application/Service Layer
 */
class FinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $service;
    private string $tenantSlug = 'finance-svc-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FinanceService::class);
    }

    // =========================================================================
    // CAP-110 — Cash Management
    // =========================================================================

    /** @test */
    public function create_cash_account_persists_and_returns_model(): void
    {
        $account = $this->service->createCashAccount(
            tenantSlug:  $this->tenantSlug,
            accountCode: 'CASH-SVC-001',
            name:        'Main Petty Cash',
            currency:    'QAR',
        );

        $this->assertNotNull($account->id);
        $this->assertSame('CASH-SVC-001', $account->account_code);
        $this->assertSame('Main Petty Cash', $account->name);
        $this->assertSame('active', $account->status);
        $this->assertEquals(0, (float) $account->balance);
    }

    /** @test */
    public function create_cash_account_rejects_duplicate_code(): void
    {
        $this->service->createCashAccount($this->tenantSlug, 'CASH-DUP', 'First');
        $this->expectException(InvalidArgumentException::class);
        $this->service->createCashAccount($this->tenantSlug, 'CASH-DUP', 'Second');
    }

    /** @test */
    public function record_cash_transaction_in_increases_balance(): void
    {
        $account = $this->service->createCashAccount($this->tenantSlug, 'CASH-BAL', 'Balance Test');

        $this->service->recordCashTransaction(
            tenantSlug:      $this->tenantSlug,
            cashAccountId:   $account->id,
            type:            'IN',
            amount:          500.00,
            transactionDate: '2026-10-03',
        );

        $account->refresh();
        $this->assertEquals(500.00, (float) $account->balance);
    }

    /** @test */
    public function record_cash_transaction_out_decreases_balance(): void
    {
        $account = $this->service->createCashAccount($this->tenantSlug, 'CASH-OUT', 'Out Test');
        $this->service->recordCashTransaction($this->tenantSlug, $account->id, 'IN', 1000.00, '2026-10-03');
        $this->service->recordCashTransaction($this->tenantSlug, $account->id, 'OUT', 300.00, '2026-10-03');

        $account->refresh();
        $this->assertEquals(700.00, (float) $account->balance);
    }

    /** @test */
    public function record_cash_transaction_rejects_invalid_type(): void
    {
        $account = $this->service->createCashAccount($this->tenantSlug, 'CASH-TYPE', 'Type Test');
        $this->expectException(InvalidArgumentException::class);
        $this->service->recordCashTransaction($this->tenantSlug, $account->id, 'INVALID', 100.00, '2026-10-03');
    }

    // =========================================================================
    // CAP-111 — Bank Management
    // =========================================================================

    /** @test */
    public function create_bank_account_persists_and_returns_model(): void
    {
        $account = $this->service->createBankAccount(
            tenantSlug:    $this->tenantSlug,
            accountCode:   'BANK-SVC-001',
            bankName:      'Qatar National Bank',
            accountNumber: '0012345678',
            iban:          'QA58QNBA000000000012345678',
        );

        $this->assertNotNull($account->id);
        $this->assertSame('BANK-SVC-001', $account->account_code);
        $this->assertSame('Qatar National Bank', $account->bank_name);
        $this->assertSame('active', $account->status);
    }

    /** @test */
    public function record_bank_transaction_credit_increases_balance(): void
    {
        $account = $this->service->createBankAccount($this->tenantSlug, 'BANK-CR', 'Credit Test', '111');

        Event::fake([BankTransactionRecorded::class]);

        $this->service->recordBankTransaction(
            tenantSlug:      $this->tenantSlug,
            bankAccountId:   $account->id,
            type:            'CREDIT',
            amount:          2000.00,
            transactionDate: '2026-10-03',
        );

        $account->refresh();
        $this->assertEquals(2000.00, (float) $account->balance);
        Event::assertDispatched(BankTransactionRecorded::class);
    }

    /** @test */
    public function record_bank_transaction_debit_decreases_balance(): void
    {
        $account = $this->service->createBankAccount($this->tenantSlug, 'BANK-DB', 'Debit Test', '222');
        Event::fake([BankTransactionRecorded::class]);
        $this->service->recordBankTransaction($this->tenantSlug, $account->id, 'CREDIT', 5000.00, '2026-10-03');
        $this->service->recordBankTransaction($this->tenantSlug, $account->id, 'DEBIT', 1500.00, '2026-10-03');

        $account->refresh();
        $this->assertEquals(3500.00, (float) $account->balance);
    }

    /** @test */
    public function reconcile_bank_marks_transactions_as_reconciled(): void
    {
        $account = $this->service->createBankAccount($this->tenantSlug, 'BANK-REC', 'Reconcile Test', '333');
        Event::fake([BankTransactionRecorded::class]);
        $this->service->recordBankTransaction($this->tenantSlug, $account->id, 'CREDIT', 1000.00, '2026-10-01');
        $this->service->recordBankTransaction($this->tenantSlug, $account->id, 'CREDIT', 500.00, '2026-10-02');

        $rec = $this->service->reconcileBank(
            tenantSlug:       $this->tenantSlug,
            bankAccountId:    $account->id,
            periodStart:      '2026-10-01',
            periodEnd:        '2026-10-31',
            statementBalance: 1500.00,
        );

        $this->assertSame('COMPLETED', $rec->status);
        $this->assertEquals(1500.00, (float) $rec->statement_balance);

        $unreconciledCount = BankTransaction::where('bank_account_id', $account->id)
            ->where('is_reconciled', false)
            ->count();
        $this->assertEquals(0, $unreconciledCount);
    }

    // =========================================================================
    // CAP-180 — Client Billing & Invoicing
    // =========================================================================

    /** @test */
    public function create_invoice_persists_as_draft(): void
    {
        $clientId = Str::uuid()->toString();
        $invoice = $this->service->createInvoice(
            tenantSlug:    $this->tenantSlug,
            invoiceNumber: 'INV-SVC-001',
            clientId:      $clientId,
            invoiceDate:   '2026-10-03',
        );

        $this->assertNotNull($invoice->id);
        $this->assertSame('DRAFT', $invoice->status);
        $this->assertSame($clientId, $invoice->client_id);
    }

    /** @test */
    public function add_invoice_item_updates_invoice_totals(): void
    {
        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-ITEM', Str::uuid()->toString(), '2026-10-03');

        $this->service->addInvoiceItem(
            tenantSlug:  $this->tenantSlug,
            invoiceId:   $invoice->id,
            description: 'Consulting Services',
            quantity:    10.0,
            unitPrice:   100.0,
            taxRate:     0.0,
        );

        $invoice->refresh();
        $this->assertEquals(1000.00, (float) $invoice->total_amount);
    }

    /** @test */
    public function issue_invoice_changes_status_and_creates_receivable(): void
    {
        Event::fake([InvoiceIssued::class]);

        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-ISSUE', Str::uuid()->toString(), '2026-10-03');
        $this->service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item 1', 1.0, 500.0);

        $issued = $this->service->issueInvoice($this->tenantSlug, $invoice->id);

        $this->assertSame('ISSUED', $issued->status);

        $receivable = Receivable::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($receivable);
        $this->assertSame('OPEN', $receivable->status);
        $this->assertEquals(500.00, (float) $receivable->original_amount);

        Event::assertDispatched(InvoiceIssued::class);
    }

    /** @test */
    public function cannot_issue_invoice_with_zero_total(): void
    {
        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-ZERO', Str::uuid()->toString(), '2026-10-03');
        $this->expectException(InvalidArgumentException::class);
        $this->service->issueInvoice($this->tenantSlug, $invoice->id);
    }

    /** @test */
    public function cannot_add_item_to_issued_invoice(): void
    {
        Event::fake([InvoiceIssued::class]);
        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-NOITEM', Str::uuid()->toString(), '2026-10-03');
        $this->service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item', 1.0, 100.0);
        $this->service->issueInvoice($this->tenantSlug, $invoice->id);

        $this->expectException(InvalidArgumentException::class);
        $this->service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Extra', 1.0, 50.0);
    }

    // =========================================================================
    // CAP-181 — Receivables & Collections
    // =========================================================================

    /** @test */
    public function record_collection_updates_receivable_and_invoice(): void
    {
        Event::fake([InvoiceIssued::class, PaymentReceived::class]);

        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-COL', Str::uuid()->toString(), '2026-10-03');
        $this->service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item', 1.0, 1000.0);
        $this->service->issueInvoice($this->tenantSlug, $invoice->id);

        $receivable = Receivable::where('invoice_id', $invoice->id)->first();

        $this->service->recordCollection(
            tenantSlug:     $this->tenantSlug,
            receivableId:   $receivable->id,
            amount:         600.00,
            collectionDate: '2026-10-03',
            paymentMethod:  'BANK_TRANSFER',
        );

        $receivable->refresh();
        $this->assertEquals(600.00, (float) $receivable->collected_amount);
        $this->assertEquals(400.00, (float) $receivable->outstanding_amount);
        $this->assertSame('PARTIALLY_COLLECTED', $receivable->status);

        $invoice->refresh();
        $this->assertSame('PARTIALLY_PAID', $invoice->status);

        Event::assertDispatched(PaymentReceived::class);
    }

    /** @test */
    public function full_collection_marks_receivable_as_collected(): void
    {
        Event::fake([InvoiceIssued::class, PaymentReceived::class]);

        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-FULL', Str::uuid()->toString(), '2026-10-03');
        $this->service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item', 1.0, 500.0);
        $this->service->issueInvoice($this->tenantSlug, $invoice->id);

        $receivable = Receivable::where('invoice_id', $invoice->id)->first();
        $this->service->recordCollection($this->tenantSlug, $receivable->id, 500.00, '2026-10-03', 'CASH');

        $receivable->refresh();
        $this->assertSame('COLLECTED', $receivable->status);

        $invoice->refresh();
        $this->assertSame('PAID', $invoice->status);
    }

    /** @test */
    public function collection_exceeding_outstanding_is_rejected(): void
    {
        Event::fake([InvoiceIssued::class]);

        $invoice = $this->service->createInvoice($this->tenantSlug, 'INV-OVER', Str::uuid()->toString(), '2026-10-03');
        $this->service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item', 1.0, 200.0);
        $this->service->issueInvoice($this->tenantSlug, $invoice->id);

        $receivable = Receivable::where('invoice_id', $invoice->id)->first();

        $this->expectException(InvalidArgumentException::class);
        $this->service->recordCollection($this->tenantSlug, $receivable->id, 999.00, '2026-10-03', 'CASH');
    }

    /** @test */
    public function record_payment_persists_supplier_payment(): void
    {
        $payment = $this->service->recordPayment(
            tenantSlug:    $this->tenantSlug,
            paymentNumber: 'PAY-SVC-001',
            paymentType:   'SUPPLIER',
            paymentMethod: 'BANK_TRANSFER',
            partyId:       Str::uuid()->toString(),
            partyType:     'supplier',
            amount:        5000.00,
            paymentDate:   '2026-10-03',
        );

        $this->assertNotNull($payment->id);
        $this->assertSame('SUPPLIER', $payment->payment_type);
        $this->assertEquals(5000.00, (float) $payment->amount);
    }

    // =========================================================================
    // Public Capabilities
    // =========================================================================

    /** @test */
    public function get_cash_position_returns_totals(): void
    {
        $this->service->createCashAccount($this->tenantSlug, 'CASH-POS1', 'Cash 1');
        $this->service->createCashAccount($this->tenantSlug, 'CASH-POS2', 'Cash 2');
        $this->service->createBankAccount($this->tenantSlug, 'BANK-POS1', 'Bank 1', '001');

        $position = $this->service->getCashPosition($this->tenantSlug);

        $this->assertArrayHasKey('cash_balance', $position);
        $this->assertArrayHasKey('bank_balance', $position);
        $this->assertArrayHasKey('total', $position);
    }

    /** @test */
    public function get_bank_balance_returns_correct_value(): void
    {
        $account = $this->service->createBankAccount($this->tenantSlug, 'BANK-BAL', 'Balance Bank', '999');
        Event::fake([BankTransactionRecorded::class]);
        $this->service->recordBankTransaction($this->tenantSlug, $account->id, 'CREDIT', 3000.00, '2026-10-03');

        $balance = $this->service->getBankBalance($account->id);
        $this->assertEquals(3000.00, $balance);
    }
}
