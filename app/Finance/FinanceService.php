<?php

declare(strict_types=1);

namespace App\Finance;

use App\Audit\AuditService;
use App\Finance\Events\BankTransactionRecorded;
use App\Finance\Events\InvoiceIssued;
use App\Finance\Events\PaymentReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * FinanceService — Application/Service Layer for Finance module.
 *
 * Architectural constraints (TASK-013B / A1 MOD-13):
 *  - Implements Finance use cases: CAP-110 (Cash), CAP-111 (Bank), CAP-180 (Invoicing), CAP-181 (Receivables).
 *  - Dispatches Domain Events: InvoiceIssued, PaymentReceived, BankTransactionRecorded.
 *  - Integrates with AuditService per established conventions.
 *  - Tenant context resolved by caller via tenant_slug — never cross-tenant access.
 *  - No tenant_id — isolation is physical (Schema-per-Tenant) per OD-001.
 *  - No Auth::user() in business logic — actorId passed as parameter.
 *  - MUST NOT create Accounting Journal Entries directly — fires Events only (DEC-013).
 *  - MUST NOT depend on Accounting module.
 *  - MUST NOT implement PSP/Digital Payments (DEC-006 — Payments Module).
 *
 * TASK-013B — Finance Application/Service Layer
 */
class FinanceService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // =========================================================================
    // CAP-110 — Cash Management
    // =========================================================================

    public function createCashAccount(
        string $tenantSlug,
        string $accountCode,
        string $name,
        string|null $description = null,
        string $currency = 'QAR',
        string|null $actorId = null,
    ): CashAccount {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($accountCode)) throw new InvalidArgumentException('account_code is required.');
        if (empty($name)) throw new InvalidArgumentException('name is required.');

        if (CashAccount::where('account_code', $accountCode)->exists()) {
            throw new InvalidArgumentException("account_code already exists: {$accountCode}");
        }

        $account = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $accountCode, $name, $description, $currency
        ) {
            return CashAccount::create([
                'account_code' => $accountCode,
                'name'         => $name,
                'description'  => $description,
                'currency'     => $currency,
                'balance'      => 0,
                'status'       => 'active',
                'tenant_slug'  => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'finance.cash_account.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => CashAccount::class,
                'subject_id'   => $account->id,
                'after'        => ['account_code' => $accountCode, 'name' => $name],
            ]
        );

        return $account;
    }

    public function recordCashTransaction(
        string $tenantSlug,
        string $cashAccountId,
        string $type,
        float $amount,
        string $transactionDate,
        string|null $reference = null,
        string|null $description = null,
        string|null $actorId = null,
    ): CashTransaction {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (!in_array($type, ['IN', 'OUT'], true)) throw new InvalidArgumentException("type must be IN or OUT.");
        if ($amount <= 0) throw new InvalidArgumentException('amount must be positive.');

        $account = CashAccount::findOrFail($cashAccountId);

        $transaction = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $cashAccountId, $type, $amount, $transactionDate, $reference, $description, $actorId, $account
        ) {
            $tx = CashTransaction::create([
                'cash_account_id'  => $cashAccountId,
                'type'             => $type,
                'amount'           => $amount,
                'reference'        => $reference,
                'description'      => $description,
                'transaction_date' => $transactionDate,
                'recorded_by'      => $actorId,
                'tenant_slug'      => $tenantSlug,
            ]);

            // Update balance
            if ($type === 'IN') {
                $account->increment('balance', $amount);
            } else {
                $account->decrement('balance', $amount);
            }

            return $tx;
        });

        $this->auditService->logEvent(
            'finance.cash_transaction.recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => CashTransaction::class,
                'subject_id'   => $transaction->id,
                'after'        => ['type' => $type, 'amount' => $amount],
            ]
        );

        return $transaction;
    }

    // =========================================================================
    // CAP-111 — Bank Management
    // =========================================================================

    public function createBankAccount(
        string $tenantSlug,
        string $accountCode,
        string $bankName,
        string $accountNumber,
        string|null $iban = null,
        string $currency = 'QAR',
        string|null $actorId = null,
    ): BankAccount {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($accountCode)) throw new InvalidArgumentException('account_code is required.');
        if (empty($bankName)) throw new InvalidArgumentException('bank_name is required.');
        if (empty($accountNumber)) throw new InvalidArgumentException('account_number is required.');

        if (BankAccount::where('account_code', $accountCode)->exists()) {
            throw new InvalidArgumentException("account_code already exists: {$accountCode}");
        }

        $account = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $accountCode, $bankName, $accountNumber, $iban, $currency
        ) {
            return BankAccount::create([
                'account_code'   => $accountCode,
                'bank_name'      => $bankName,
                'account_number' => $accountNumber,
                'iban'           => $iban,
                'currency'       => $currency,
                'balance'        => 0,
                'status'         => 'active',
                'tenant_slug'    => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'finance.bank_account.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => BankAccount::class,
                'subject_id'   => $account->id,
                'after'        => ['account_code' => $accountCode, 'bank_name' => $bankName],
            ]
        );

        return $account;
    }

    public function recordBankTransaction(
        string $tenantSlug,
        string $bankAccountId,
        string $type,
        float $amount,
        string $transactionDate,
        string|null $reference = null,
        string|null $description = null,
        string|null $actorId = null,
    ): BankTransaction {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (!in_array($type, ['CREDIT', 'DEBIT'], true)) throw new InvalidArgumentException("type must be CREDIT or DEBIT.");
        if ($amount <= 0) throw new InvalidArgumentException('amount must be positive.');

        $account = BankAccount::findOrFail($bankAccountId);

        $transaction = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $bankAccountId, $type, $amount, $transactionDate, $reference, $description, $actorId, $account
        ) {
            $tx = BankTransaction::create([
                'bank_account_id'  => $bankAccountId,
                'type'             => $type,
                'amount'           => $amount,
                'reference'        => $reference,
                'description'      => $description,
                'transaction_date' => $transactionDate,
                'is_reconciled'    => false,
                'recorded_by'      => $actorId,
                'tenant_slug'      => $tenantSlug,
            ]);

            // Update balance
            if ($type === 'CREDIT') {
                $account->increment('balance', $amount);
            } else {
                $account->decrement('balance', $amount);
            }

            return $tx;
        });

        $this->auditService->logEvent(
            'finance.bank_transaction.recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => BankTransaction::class,
                'subject_id'   => $transaction->id,
                'after'        => ['type' => $type, 'amount' => $amount],
            ]
        );

        // Fire domain event — Accounting listens to create Journal Entry (DEC-013)
        Event::dispatch(new BankTransactionRecorded(
            transactionId: $transaction->id,
            bankAccountId: $bankAccountId,
            type: $type,
            amount: $amount,
            tenantSlug: $tenantSlug,
            accountingDate: $transactionDate,
            transactionType: $type,
        ));

        return $transaction;
    }

    public function reconcileBank(
        string $tenantSlug,
        string $bankAccountId,
        string $periodStart,
        string $periodEnd,
        float $statementBalance,
        string|null $notes = null,
        string|null $actorId = null,
    ): BankReconciliation {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');

        $account = BankAccount::findOrFail($bankAccountId);
        $bookBalance = (float) $account->balance;
        $difference = $statementBalance - $bookBalance;

        $reconciliation = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $bankAccountId, $periodStart, $periodEnd,
            $statementBalance, $bookBalance, $difference, $notes, $actorId
        ) {
            $rec = BankReconciliation::create([
                'bank_account_id'   => $bankAccountId,
                'period_start'      => $periodStart,
                'period_end'        => $periodEnd,
                'statement_balance' => $statementBalance,
                'book_balance'      => $bookBalance,
                'difference'        => $difference,
                'status'            => 'COMPLETED',
                'reconciled_by'     => $actorId,
                'reconciled_at'     => now(),
                'notes'             => $notes,
                'tenant_slug'       => $tenantSlug,
            ]);

            // Mark all unreconciled transactions in period as reconciled
            BankTransaction::where('bank_account_id', $bankAccountId)
                ->where('is_reconciled', false)
                ->whereBetween('transaction_date', [$periodStart, $periodEnd])
                ->update(['is_reconciled' => true, 'reconciliation_id' => $rec->id]);

            return $rec;
        });

        $this->auditService->logEvent(
            'finance.bank_reconciliation.completed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => BankReconciliation::class,
                'subject_id'   => $reconciliation->id,
                'after'        => ['difference' => $difference, 'status' => 'COMPLETED'],
            ]
        );

        return $reconciliation;
    }

    // =========================================================================
    // CAP-180 — Client Billing & Invoicing
    // =========================================================================

    public function createInvoice(
        string $tenantSlug,
        string $invoiceNumber,
        string $clientId,
        string $invoiceDate,
        string|null $projectId = null,
        string|null $contractId = null,
        string|null $dueDate = null,
        string|null $notes = null,
        string|null $actorId = null,
    ): Invoice {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($invoiceNumber)) throw new InvalidArgumentException('invoice_number is required.');
        if (empty($clientId)) throw new InvalidArgumentException('client_id is required.');

        if (Invoice::where('invoice_number', $invoiceNumber)->exists()) {
            throw new InvalidArgumentException("invoice_number already exists: {$invoiceNumber}");
        }

        $invoice = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $invoiceNumber, $clientId, $invoiceDate, $projectId, $contractId, $dueDate, $notes, $actorId
        ) {
            return Invoice::create([
                'invoice_number' => $invoiceNumber,
                'client_id'      => $clientId,
                'project_id'     => $projectId,
                'contract_id'    => $contractId,
                'invoice_date'   => $invoiceDate,
                'due_date'       => $dueDate,
                'status'         => 'DRAFT',
                'notes'          => $notes,
                'issued_by'      => $actorId,
                'tenant_slug'    => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'finance.invoice.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Invoice::class,
                'subject_id'   => $invoice->id,
                'after'        => ['invoice_number' => $invoiceNumber, 'status' => 'DRAFT'],
            ]
        );

        return $invoice;
    }

    public function addInvoiceItem(
        string $tenantSlug,
        string $invoiceId,
        string $description,
        float $quantity,
        float $unitPrice,
        float $taxRate = 0.0,
        string|null $actorId = null,
    ): InvoiceItem {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if ($quantity <= 0) throw new InvalidArgumentException('quantity must be positive.');
        if ($unitPrice < 0) throw new InvalidArgumentException('unit_price must be non-negative.');

        $invoice = Invoice::findOrFail($invoiceId);
        if (!$invoice->isDraft()) {
            throw new InvalidArgumentException('Cannot add items to a non-DRAFT invoice.');
        }

        $lineTotal = round($quantity * $unitPrice * (1 + $taxRate), 4);

        $item = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $invoiceId, $description, $quantity, $unitPrice, $taxRate, $lineTotal, $invoice
        ) {
            $item = InvoiceItem::create([
                'invoice_id'  => $invoiceId,
                'description' => $description,
                'quantity'    => $quantity,
                'unit_price'  => $unitPrice,
                'tax_rate'    => $taxRate,
                'line_total'  => $lineTotal,
                'tenant_slug' => $tenantSlug,
            ]);

            // Recalculate invoice totals
            $subtotal = InvoiceItem::where('invoice_id', $invoiceId)
                ->selectRaw('SUM(quantity * unit_price) as subtotal')
                ->value('subtotal') ?? 0;
            $taxAmount = InvoiceItem::where('invoice_id', $invoiceId)
                ->selectRaw('SUM(quantity * unit_price * tax_rate) as tax_total')
                ->value('tax_total') ?? 0;
            $total = $subtotal + $taxAmount;

            $invoice->update([
                'subtotal'     => $subtotal,
                'tax_amount'   => $taxAmount,
                'total_amount' => $total,
            ]);

            return $item;
        });

        return $item;
    }

    public function issueInvoice(
        string $tenantSlug,
        string $invoiceId,
        string|null $actorId = null,
    ): Invoice {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');

        $invoice = Invoice::findOrFail($invoiceId);
        if (!$invoice->isDraft()) {
            throw new InvalidArgumentException('Only DRAFT invoices can be issued.');
        }
        if ($invoice->total_amount <= 0) {
            throw new InvalidArgumentException('Cannot issue an invoice with zero total.');
        }

        DB::connection('tenant')->transaction(function () use ($invoice, $tenantSlug, $actorId) {
            $invoice->update(['status' => 'ISSUED']);

            // Create Receivable
            Receivable::create([
                'invoice_id'        => $invoice->id,
                'client_id'         => $invoice->client_id,
                'original_amount'   => $invoice->total_amount,
                'collected_amount'  => 0,
                'outstanding_amount' => $invoice->total_amount,
                'due_date'          => $invoice->due_date,
                'status'            => 'OPEN',
                'tenant_slug'       => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'finance.invoice.issued',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Invoice::class,
                'subject_id'   => $invoice->id,
                'after'        => ['status' => 'ISSUED', 'total_amount' => $invoice->total_amount],
            ]
        );

        // Fire domain event — Accounting listens (DEC-013)
        Event::dispatch(new InvoiceIssued(
            invoiceId: $invoice->id,
            clientId: $invoice->client_id,
            projectId: $invoice->project_id,
            totalAmount: (float) $invoice->total_amount,
            tenantSlug: $tenantSlug,
            accountingDate: (string) $invoice->invoice_date,
        ));

        $invoice->refresh();
        return $invoice;
    }

    // =========================================================================
    // CAP-181 — Receivables & Collections
    // =========================================================================

    public function recordCollection(
        string $tenantSlug,
        string $receivableId,
        float $amount,
        string $collectionDate,
        string $paymentMethod,
        string|null $reference = null,
        string|null $notes = null,
        string|null $actorId = null,
        string|null $bankAccountId = null,
        string|null $cashAccountId = null,
    ): Collection {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if ($amount <= 0) throw new InvalidArgumentException('amount must be positive.');
        if (!in_array($paymentMethod, ['CASH', 'BANK_TRANSFER', 'CHEQUE'], true)) {
            throw new InvalidArgumentException('payment_method must be CASH, BANK_TRANSFER, or CHEQUE.');
        }

        $receivable = Receivable::findOrFail($receivableId);
        if ($receivable->isCollected()) {
            throw new InvalidArgumentException('Receivable is already fully collected.');
        }
        if ($amount > (float) $receivable->outstanding_amount) {
            throw new InvalidArgumentException('Collection amount exceeds outstanding amount.');
        }

        $collection = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $receivableId, $amount, $collectionDate, $paymentMethod, $reference, $notes, $actorId, $receivable, $bankAccountId, $cashAccountId
        ) {
            $col = Collection::create([
                'receivable_id'   => $receivableId,
                'invoice_id'      => $receivable->invoice_id,
                'amount'          => $amount,
                'collection_date' => $collectionDate,
                'payment_method'  => $paymentMethod,
                'reference'       => $reference,
                'notes'           => $notes,
                'recorded_by'     => $actorId,
                'tenant_slug'     => $tenantSlug,
                'bank_account_id' => $bankAccountId,
                'cash_account_id' => $cashAccountId,
            ]);

            // Update receivable
            $newCollected = (float) $receivable->collected_amount + $amount;
            $newOutstanding = (float) $receivable->original_amount - $newCollected;
            $newStatus = $newOutstanding <= 0 ? 'COLLECTED' : 'PARTIALLY_COLLECTED';

            $receivable->update([
                'collected_amount'  => $newCollected,
                'outstanding_amount' => max(0, $newOutstanding),
                'status'            => $newStatus,
            ]);

            // Update invoice paid_amount and status
            $invoice = Invoice::find($receivable->invoice_id);
            if ($invoice) {
                $newPaid = (float) $invoice->paid_amount + $amount;
                $invoiceStatus = $newPaid >= (float) $invoice->total_amount ? 'PAID' : 'PARTIALLY_PAID';
                $invoice->update(['paid_amount' => $newPaid, 'status' => $invoiceStatus]);
            }

            return $col;
        });

        $this->auditService->logEvent(
            'finance.collection.recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Collection::class,
                'subject_id'   => $collection->id,
                'after'        => ['amount' => $amount, 'payment_method' => $paymentMethod],
            ]
        );

        // Fire domain event — Accounting listens (DEC-013)
        Event::dispatch(new PaymentReceived(
            paymentId: $collection->id,
            invoiceId: $receivable->invoice_id,
            amount: $amount,
            tenantSlug: $tenantSlug,
            accountingDate: $collectionDate,
            paymentMethod: $paymentMethod,
            bankAccountId: $bankAccountId,
            cashAccountId: $cashAccountId,
        ));

        return $collection;
    }

    public function recordPayment(
        string $tenantSlug,
        string $paymentNumber,
        string $paymentType,
        string $paymentMethod,
        string $partyId,
        string $partyType,
        float $amount,
        string $paymentDate,
        string|null $invoiceId = null,
        string|null $bankAccountId = null,
        string|null $reference = null,
        string|null $notes = null,
        string|null $actorId = null,
    ): Payment {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (!in_array($paymentType, ['SUPPLIER', 'CLIENT'], true)) {
            throw new InvalidArgumentException('payment_type must be SUPPLIER or CLIENT.');
        }
        if (!in_array($paymentMethod, ['CASH', 'BANK_TRANSFER', 'CHEQUE'], true)) {
            throw new InvalidArgumentException('payment_method must be CASH, BANK_TRANSFER, or CHEQUE.');
        }
        if ($amount <= 0) throw new InvalidArgumentException('amount must be positive.');

        if (Payment::where('payment_number', $paymentNumber)->exists()) {
            throw new InvalidArgumentException("payment_number already exists: {$paymentNumber}");
        }

        $payment = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $paymentNumber, $paymentType, $paymentMethod,
            $partyId, $partyType, $invoiceId, $bankAccountId, $amount, $paymentDate,
            $reference, $notes, $actorId
        ) {
            return Payment::create([
                'payment_number'  => $paymentNumber,
                'payment_type'    => $paymentType,
                'payment_method'  => $paymentMethod,
                'party_id'        => $partyId,
                'party_type'      => $partyType,
                'invoice_id'      => $invoiceId,
                'bank_account_id' => $bankAccountId,
                'amount'          => $amount,
                'payment_date'    => $paymentDate,
                'reference'       => $reference,
                'notes'           => $notes,
                'recorded_by'     => $actorId,
                'tenant_slug'     => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'finance.payment.recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Payment::class,
                'subject_id'   => $payment->id,
                'after'        => ['payment_number' => $paymentNumber, 'amount' => $amount, 'payment_type' => $paymentType],
            ]
        );

        return $payment;
    }

    // =========================================================================
    // Public Capabilities (A1 MOD-13 Contracts)
    // =========================================================================

    public function getInvoice(string $invoiceId): Invoice
    {
        return Invoice::findOrFail($invoiceId);
    }

    public function getPaymentStatus(string $paymentId): string
    {
        $payment = Payment::findOrFail($paymentId);
        return 'recorded'; // Payments are always recorded once created
    }

    public function getCashPosition(string $tenantSlug): array
    {
        $cashTotal = CashAccount::where('tenant_slug', $tenantSlug)
            ->where('status', 'active')
            ->sum('balance');
        $bankTotal = BankAccount::where('tenant_slug', $tenantSlug)
            ->where('status', 'active')
            ->sum('balance');

        return [
            'cash_balance' => (float) $cashTotal,
            'bank_balance' => (float) $bankTotal,
            'total'        => (float) $cashTotal + (float) $bankTotal,
        ];
    }

    public function getBankBalance(string $bankAccountId): float
    {
        $account = BankAccount::findOrFail($bankAccountId);
        return (float) $account->balance;
    }

    // =========================================================================
    // WEB-001L — List Endpoints (CAP-110, CAP-111, CAP-180, CAP-181)
    // =========================================================================

    /**
     * List invoices for a tenant.
     * CAP-180 — Client Billing & Invoicing
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Invoice>
     */
    public function listInvoices(string $tenantSlug): \Illuminate\Database\Eloquent\Collection
    {
        return Invoice::where('tenant_slug', $tenantSlug)
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * List bank accounts for a tenant.
     * CAP-111 — Bank Management
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BankAccount>
     */
    public function listBankAccounts(string $tenantSlug): \Illuminate\Database\Eloquent\Collection
    {
        return BankAccount::where('tenant_slug', $tenantSlug)
            ->orderBy('account_code')
            ->get();
    }

    /**
     * List cash accounts for a tenant.
     * CAP-110 — Cash Management
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CashAccount>
     */
    public function listCashAccounts(string $tenantSlug): \Illuminate\Database\Eloquent\Collection
    {
        return CashAccount::where('tenant_slug', $tenantSlug)
            ->orderBy('account_code')
            ->get();
    }

    /**
     * List payments for a tenant.
     * CAP-181 — Receivables & Collections
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Payment>
     */
    public function listPayments(string $tenantSlug): \Illuminate\Database\Eloquent\Collection
    {
        return Payment::where('tenant_slug', $tenantSlug)
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * List collections for a tenant.
     * CAP-181 — Receivables & Collections
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Collection>
     */
    public function listCollections(string $tenantSlug): \Illuminate\Database\Eloquent\Collection
    {
        return Collection::where('tenant_slug', $tenantSlug)
            ->orderByDesc('collection_date')
            ->orderByDesc('created_at')
            ->get();
    }
}
