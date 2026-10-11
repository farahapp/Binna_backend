<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Finance\BankAccount;
use App\Finance\BankReconciliation;
use App\Finance\BankTransaction;
use App\Finance\CashAccount;
use App\Finance\CashTransaction;
use App\Finance\Collection;
use App\Finance\FinanceService;
use App\Finance\Invoice;
use App\Finance\InvoiceItem;
use App\Finance\Payment;
use App\Finance\Receivable;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FinanceFoundationTest — Feature tests for Finance Domain/Data Foundation.
 * TASK-013A — Finance Domain/Data Foundation
 * A1 MOD-13 — CAP-110/111/180/181
 */
class FinanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_accounts_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('cash_accounts'),
            'cash_accounts table must exist in the tenant schema'
        );
    }

    /** @test */
    public function cash_transactions_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('cash_transactions'),
            'cash_transactions table must exist in the tenant schema'
        );
    }

    /** @test */
    public function bank_accounts_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bank_accounts'),
            'bank_accounts table must exist in the tenant schema'
        );
    }

    /** @test */
    public function bank_transactions_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bank_transactions'),
            'bank_transactions table must exist in the tenant schema'
        );
    }

    /** @test */
    public function bank_reconciliations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bank_reconciliations'),
            'bank_reconciliations table must exist in the tenant schema'
        );
    }

    /** @test */
    public function invoices_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('invoices'),
            'invoices table must exist in the tenant schema'
        );
    }

    /** @test */
    public function invoice_items_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('invoice_items'),
            'invoice_items table must exist in the tenant schema'
        );
    }

    /** @test */
    public function payments_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('payments'),
            'payments table must exist in the tenant schema'
        );
    }

    /** @test */
    public function receivables_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('receivables'),
            'receivables table must exist in the tenant schema'
        );
    }

    /** @test */
    public function collections_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('collections'),
            'collections table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation — No tenant_id Tests (OD-001)
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_accounts_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('cash_accounts', 'tenant_id'),
            'cash_accounts must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function bank_accounts_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('bank_accounts', 'tenant_id'),
            'bank_accounts must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function invoices_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('invoices', 'tenant_id'),
            'invoices must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function receivables_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('receivables', 'tenant_id'),
            'receivables must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // tenant_slug presence
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_accounts_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('cash_accounts', 'tenant_slug'),
            'cash_accounts must have tenant_slug for cross-schema traceability'
        );
    }

    /** @test */
    public function invoices_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('invoices', 'tenant_slug'),
            'invoices must have tenant_slug for cross-schema traceability'
        );
    }

    // -------------------------------------------------------------------------
    // Model connection
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_account_model_uses_tenant_connection(): void
    {
        $model = new CashAccount();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    /** @test */
    public function bank_account_model_uses_tenant_connection(): void
    {
        $model = new BankAccount();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    /** @test */
    public function invoice_model_uses_tenant_connection(): void
    {
        $model = new Invoice();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    /** @test */
    public function receivable_model_uses_tenant_connection(): void
    {
        $model = new Receivable();
        $this->assertSame('tenant', $model->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // UUID PK
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_account_has_uuid_primary_key(): void
    {
        $account = CashAccount::create([
            'account_code' => 'CASH-001',
            'name'         => 'Petty Cash',
            'currency'     => 'QAR',
            'tenant_slug'  => 'test-tenant',
        ]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $account->id,
            'CashAccount PK must be a UUID'
        );
    }

    /** @test */
    public function invoice_has_uuid_primary_key(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-001',
            'client_id'      => \Illuminate\Support\Str::uuid()->toString(),
            'invoice_date'   => '2026-10-03',
            'tenant_slug'    => 'test-tenant',
        ]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $invoice->id,
            'Invoice PK must be a UUID'
        );
    }

    // -------------------------------------------------------------------------
    // Model defaults
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_account_defaults_to_qar_currency_and_zero_balance(): void
    {
        $account = CashAccount::create([
            'account_code' => 'CASH-DEF',
            'name'         => 'Default Cash',
            'tenant_slug'  => 'test-tenant',
        ]);
        $this->assertSame('QAR', $account->currency);
        $this->assertEquals(0, (float) $account->balance);
        $this->assertSame('active', $account->status);
    }

    /** @test */
    public function invoice_defaults_to_draft_status(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-DEF',
            'client_id'      => \Illuminate\Support\Str::uuid()->toString(),
            'invoice_date'   => '2026-10-03',
            'tenant_slug'    => 'test-tenant',
        ]);
        $this->assertSame('DRAFT', $invoice->status);
        $this->assertEquals(0, (float) $invoice->total_amount);
    }

    /** @test */
    public function receivable_defaults_to_open_status(): void
    {
        $invoiceId = \Illuminate\Support\Str::uuid()->toString();
        $receivable = Receivable::create([
            'invoice_id'        => $invoiceId,
            'client_id'         => \Illuminate\Support\Str::uuid()->toString(),
            'original_amount'   => 1000.00,
            'outstanding_amount' => 1000.00,
            'tenant_slug'       => 'test-tenant',
        ]);
        $this->assertSame('OPEN', $receivable->status);
        $this->assertEquals(0, (float) $receivable->collected_amount);
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /** @test */
    public function invoice_status_helpers_work_correctly(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-STAT',
            'client_id'      => \Illuminate\Support\Str::uuid()->toString(),
            'invoice_date'   => '2026-10-03',
            'status'         => 'DRAFT',
            'tenant_slug'    => 'test-tenant',
        ]);
        $this->assertTrue($invoice->isDraft());
        $this->assertFalse($invoice->isIssued());
        $this->assertFalse($invoice->isPaid());
    }

    /** @test */
    public function bank_transaction_type_helpers_work(): void
    {
        $bankAccount = BankAccount::create([
            'account_code'   => 'BANK-001',
            'bank_name'      => 'Test Bank',
            'account_number' => '1234567890',
            'tenant_slug'    => 'test-tenant',
        ]);
        $tx = BankTransaction::create([
            'bank_account_id'  => $bankAccount->id,
            'type'             => 'CREDIT',
            'amount'           => 500.00,
            'transaction_date' => '2026-10-03',
            'tenant_slug'      => 'test-tenant',
        ]);
        $this->assertTrue($tx->isCredit());
        $this->assertFalse($tx->isDebit());
        $this->assertFalse($tx->isReconciled());
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @test */
    public function cash_account_has_many_transactions(): void
    {
        $account = CashAccount::create([
            'account_code' => 'CASH-REL',
            'name'         => 'Relation Test',
            'tenant_slug'  => 'test-tenant',
        ]);
        CashTransaction::create([
            'cash_account_id'  => $account->id,
            'type'             => 'IN',
            'amount'           => 100.00,
            'transaction_date' => '2026-10-03',
            'tenant_slug'      => 'test-tenant',
        ]);
        $this->assertCount(1, $account->transactions);
    }

    /** @test */
    public function invoice_has_many_items(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-REL',
            'client_id'      => \Illuminate\Support\Str::uuid()->toString(),
            'invoice_date'   => '2026-10-03',
            'tenant_slug'    => 'test-tenant',
        ]);
        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => 'Test Item',
            'quantity'    => 1,
            'unit_price'  => 100.00,
            'line_total'  => 100.00,
            'tenant_slug' => 'test-tenant',
        ]);
        $this->assertCount(1, $invoice->items);
    }

    /** @test */
    public function receivable_has_many_collections(): void
    {
        $invoiceId = \Illuminate\Support\Str::uuid()->toString();
        $receivable = Receivable::create([
            'invoice_id'        => $invoiceId,
            'client_id'         => \Illuminate\Support\Str::uuid()->toString(),
            'original_amount'   => 500.00,
            'outstanding_amount' => 500.00,
            'tenant_slug'       => 'test-tenant',
        ]);
        Collection::create([
            'receivable_id'   => $receivable->id,
            'invoice_id'      => $invoiceId,
            'amount'          => 200.00,
            'collection_date' => '2026-10-03',
            'payment_method'  => 'CASH',
            'tenant_slug'     => 'test-tenant',
        ]);
        $this->assertCount(1, $receivable->collections);
    }

    // -------------------------------------------------------------------------
    // Domain Events exist
    // -------------------------------------------------------------------------

    /** @test */
    public function invoice_issued_event_can_be_instantiated(): void
    {
        $event = new InvoiceIssued(
            invoiceId:   \Illuminate\Support\Str::uuid()->toString(),
            clientId:    \Illuminate\Support\Str::uuid()->toString(),
            projectId:   null,
            totalAmount: 1000.00,
            tenantSlug:  'test-tenant',
        );
        $this->assertInstanceOf(InvoiceIssued::class, $event);
        $this->assertEquals(1000.00, $event->totalAmount);
    }

    /** @test */
    public function payment_received_event_can_be_instantiated(): void
    {
        $event = new PaymentReceived(
            paymentId:  \Illuminate\Support\Str::uuid()->toString(),
            invoiceId:  \Illuminate\Support\Str::uuid()->toString(),
            amount:     500.00,
            tenantSlug: 'test-tenant',
        );
        $this->assertInstanceOf(PaymentReceived::class, $event);
    }

    /** @test */
    public function bank_transaction_recorded_event_can_be_instantiated(): void
    {
        $event = new BankTransactionRecorded(
            transactionId: \Illuminate\Support\Str::uuid()->toString(),
            bankAccountId: \Illuminate\Support\Str::uuid()->toString(),
            type:          'CREDIT',
            amount:        1000.00,
            tenantSlug:    'test-tenant',
        );
        $this->assertInstanceOf(BankTransactionRecorded::class, $event);
    }

    // -------------------------------------------------------------------------
    // TASK-016 — Section 3.1 — Finance Side Event Payload Verification
    // -------------------------------------------------------------------------

    /** @test */
    public function invoice_issued_event_carries_accounting_date_from_invoice_date(): void
    {
        // TASK-016 §3.1 — accounting_date must equal invoice_date (DEC-002)
        $invoiceDate = '2026-10-15';
        $event = new InvoiceIssued(
            invoiceId:      \Illuminate\Support\Str::uuid()->toString(),
            clientId:       \Illuminate\Support\Str::uuid()->toString(),
            projectId:      null,
            totalAmount:    2500.00,
            tenantSlug:     'test-tenant',
            accountingDate: $invoiceDate,
        );

        $this->assertSame($invoiceDate, $event->accountingDate,
            'InvoiceIssued.accountingDate must equal invoice_date (TASK-016 §3.1 / DEC-002)');
        $this->assertNotEmpty($event->accountingDate,
            'InvoiceIssued.accountingDate must not be empty');
    }

    /** @test */
    public function payment_received_event_carries_accounting_date_from_collection_date(): void
    {
        // TASK-016 §3.1 — accounting_date must equal collection_date (DEC-002)
        $collectionDate = '2026-10-20';
        $event = new PaymentReceived(
            paymentId:      \Illuminate\Support\Str::uuid()->toString(),
            invoiceId:      \Illuminate\Support\Str::uuid()->toString(),
            amount:         750.00,
            tenantSlug:     'test-tenant',
            accountingDate: $collectionDate,
            paymentMethod:  'BANK_TRANSFER',
            bankAccountId:  \Illuminate\Support\Str::uuid()->toString(),
            cashAccountId:  null,
        );

        $this->assertSame($collectionDate, $event->accountingDate,
            'PaymentReceived.accountingDate must equal collection_date (TASK-016 §3.1 / DEC-002)');
        $this->assertNotEmpty($event->accountingDate,
            'PaymentReceived.accountingDate must not be empty');
    }

    /** @test */
    public function payment_received_event_carries_payment_method(): void
    {
        // TASK-016 §3.1 — payment_method must be present with approved vocabulary
        foreach (['CASH', 'BANK_TRANSFER', 'CHEQUE'] as $method) {
            $event = new PaymentReceived(
                paymentId:      \Illuminate\Support\Str::uuid()->toString(),
                invoiceId:      \Illuminate\Support\Str::uuid()->toString(),
                amount:         100.00,
                tenantSlug:     'test-tenant',
                accountingDate: '2026-10-15',
                paymentMethod:  $method,
            );
            $this->assertSame($method, $event->paymentMethod,
                "PaymentReceived.paymentMethod must be '{$method}' (TASK-016 §3.1)");
        }
    }

    /** @test */
    public function payment_received_event_carries_bank_account_id_for_bank_transfer(): void
    {
        // TASK-016 §3.1 — bank_account_id required when payment_method is BANK_TRANSFER or CHEQUE
        $bankAccountId = \Illuminate\Support\Str::uuid()->toString();
        $event = new PaymentReceived(
            paymentId:      \Illuminate\Support\Str::uuid()->toString(),
            invoiceId:      \Illuminate\Support\Str::uuid()->toString(),
            amount:         500.00,
            tenantSlug:     'test-tenant',
            accountingDate: '2026-10-15',
            paymentMethod:  'BANK_TRANSFER',
            bankAccountId:  $bankAccountId,
            cashAccountId:  null,
        );

        $this->assertSame($bankAccountId, $event->bankAccountId,
            'PaymentReceived.bankAccountId must be set for BANK_TRANSFER (TASK-016 §3.1)');
        $this->assertNull($event->cashAccountId,
            'PaymentReceived.cashAccountId must be null for BANK_TRANSFER (TASK-016 §3.1)');
    }

    /** @test */
    public function payment_received_event_carries_cash_account_id_for_cash_payment(): void
    {
        // TASK-016 §3.1 — cash_account_id required when payment_method is CASH
        $cashAccountId = \Illuminate\Support\Str::uuid()->toString();
        $event = new PaymentReceived(
            paymentId:      \Illuminate\Support\Str::uuid()->toString(),
            invoiceId:      \Illuminate\Support\Str::uuid()->toString(),
            amount:         300.00,
            tenantSlug:     'test-tenant',
            accountingDate: '2026-10-15',
            paymentMethod:  'CASH',
            bankAccountId:  null,
            cashAccountId:  $cashAccountId,
        );

        $this->assertSame($cashAccountId, $event->cashAccountId,
            'PaymentReceived.cashAccountId must be set for CASH (TASK-016 §3.1)');
        $this->assertNull($event->bankAccountId,
            'PaymentReceived.bankAccountId must be null for CASH (TASK-016 §3.1)');
    }

    /** @test */
    public function payment_received_event_cheque_carries_bank_account_id(): void
    {
        // TASK-016 §3.1 — bank_account_id required when payment_method is CHEQUE
        $bankAccountId = \Illuminate\Support\Str::uuid()->toString();
        $event = new PaymentReceived(
            paymentId:      \Illuminate\Support\Str::uuid()->toString(),
            invoiceId:      \Illuminate\Support\Str::uuid()->toString(),
            amount:         800.00,
            tenantSlug:     'test-tenant',
            accountingDate: '2026-10-15',
            paymentMethod:  'CHEQUE',
            bankAccountId:  $bankAccountId,
            cashAccountId:  null,
        );

        $this->assertSame($bankAccountId, $event->bankAccountId,
            'PaymentReceived.bankAccountId must be set for CHEQUE (TASK-016 §3.1)');
        $this->assertNull($event->cashAccountId,
            'PaymentReceived.cashAccountId must be null for CHEQUE (TASK-016 §3.1)');
    }

    /** @test */
    public function bank_transaction_recorded_event_carries_accounting_date_from_transaction_date(): void
    {
        // TASK-016 §3.1 — accounting_date must equal transaction_date (DEC-002)
        $transactionDate = '2026-10-18';
        $event = new BankTransactionRecorded(
            transactionId:   \Illuminate\Support\Str::uuid()->toString(),
            bankAccountId:   \Illuminate\Support\Str::uuid()->toString(),
            type:            'CREDIT',
            amount:          5000.00,
            tenantSlug:      'test-tenant',
            accountingDate:  $transactionDate,
            transactionType: 'CREDIT',
        );

        $this->assertSame($transactionDate, $event->accountingDate,
            'BankTransactionRecorded.accountingDate must equal transaction_date (TASK-016 §3.1 / DEC-002)');
        $this->assertNotEmpty($event->accountingDate,
            'BankTransactionRecorded.accountingDate must not be empty');
    }

    /** @test */
    public function bank_transaction_recorded_event_carries_transaction_type_for_posting_rule_context(): void
    {
        // TASK-016 §3.1 — transactionType provides PostingRule context (DEC-005)
        $event = new BankTransactionRecorded(
            transactionId:   \Illuminate\Support\Str::uuid()->toString(),
            bankAccountId:   \Illuminate\Support\Str::uuid()->toString(),
            type:            'DEBIT',
            amount:          1200.00,
            tenantSlug:      'test-tenant',
            accountingDate:  '2026-10-18',
            transactionType: 'DEBIT',
        );

        $this->assertSame('DEBIT', $event->transactionType,
            'BankTransactionRecorded.transactionType must carry PostingRule context (TASK-016 §3.1)');
        $this->assertNotEmpty($event->transactionType,
            'BankTransactionRecorded.transactionType must not be empty');
    }

    // -------------------------------------------------------------------------
    // Duplicate code rejection
    // -------------------------------------------------------------------------

    /** @test */
    public function duplicate_cash_account_code_is_rejected(): void
    {
        CashAccount::create([
            'account_code' => 'CASH-DUP',
            'name'         => 'First',
            'tenant_slug'  => 'test-tenant',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CashAccount::create([
            'account_code' => 'CASH-DUP',
            'name'         => 'Second',
            'tenant_slug'  => 'test-tenant',
        ]);
    }

    /** @test */
    public function duplicate_invoice_number_is_rejected(): void
    {
        $clientId = \Illuminate\Support\Str::uuid()->toString();
        Invoice::create([
            'invoice_number' => 'INV-DUP',
            'client_id'      => $clientId,
            'invoice_date'   => '2026-10-03',
            'tenant_slug'    => 'test-tenant',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Invoice::create([
            'invoice_number' => 'INV-DUP',
            'client_id'      => $clientId,
            'invoice_date'   => '2026-10-03',
            'tenant_slug'    => 'test-tenant',
        ]);
    }
}
