<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Finance\FinanceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * FinanceController — HTTP API Layer for Finance module.
 *
 * Thin controller — all business logic delegated to FinanceService.
 * Architectural constraints (TASK-013C / A1 MOD-13):
 *  - CAP-110: Cash Management endpoints
 *  - CAP-111: Bank Management endpoints
 *  - CAP-180: Client Billing & Invoicing endpoints
 *  - CAP-181: Receivables & Collections endpoints
 *
 * TASK-013C — Finance HTTP API Layer
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceService $financeService,
    ) {}

    // =========================================================================
    // CAP-110 — Cash Management
    // =========================================================================

    public function createCashAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'  => 'required|string',
            'account_code' => 'required|string|max:50',
            'name'         => 'required|string|max:255',
            'description'  => 'nullable|string',
            'currency'     => 'nullable|string|size:3',
        ]);

        try {
            $account = $this->financeService->createCashAccount(
                tenantSlug:   $validated['tenant_slug'],
                accountCode:  $validated['account_code'],
                name:         $validated['name'],
                description:  $validated['description'] ?? null,
                currency:     $validated['currency'] ?? 'QAR',
                actorId:      $request->user()?->id,
            );
            return response()->json($account, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function recordCashTransaction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'      => 'required|string',
            'cash_account_id'  => 'required|uuid',
            'type'             => 'required|in:IN,OUT',
            'amount'           => 'required|numeric|min:0.0001',
            'transaction_date' => 'required|date',
            'reference'        => 'nullable|string|max:100',
            'description'      => 'nullable|string',
        ]);

        try {
            $tx = $this->financeService->recordCashTransaction(
                tenantSlug:      $validated['tenant_slug'],
                cashAccountId:   $validated['cash_account_id'],
                type:            $validated['type'],
                amount:          (float) $validated['amount'],
                transactionDate: $validated['transaction_date'],
                reference:       $validated['reference'] ?? null,
                description:     $validated['description'] ?? null,
                actorId:         $request->user()?->id,
            );
            return response()->json($tx, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    // =========================================================================
    // CAP-111 — Bank Management
    // =========================================================================

    public function createBankAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'    => 'required|string',
            'account_code'   => 'required|string|max:50',
            'bank_name'      => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'iban'           => 'nullable|string|max:34',
            'currency'       => 'nullable|string|size:3',
        ]);

        try {
            $account = $this->financeService->createBankAccount(
                tenantSlug:    $validated['tenant_slug'],
                accountCode:   $validated['account_code'],
                bankName:      $validated['bank_name'],
                accountNumber: $validated['account_number'],
                iban:          $validated['iban'] ?? null,
                currency:      $validated['currency'] ?? 'QAR',
                actorId:       $request->user()?->id,
            );
            return response()->json($account, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function recordBankTransaction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'      => 'required|string',
            'bank_account_id'  => 'required|uuid',
            'type'             => 'required|in:CREDIT,DEBIT',
            'amount'           => 'required|numeric|min:0.0001',
            'transaction_date' => 'required|date',
            'reference'        => 'nullable|string|max:100',
            'description'      => 'nullable|string',
        ]);

        try {
            $tx = $this->financeService->recordBankTransaction(
                tenantSlug:      $validated['tenant_slug'],
                bankAccountId:   $validated['bank_account_id'],
                type:            $validated['type'],
                amount:          (float) $validated['amount'],
                transactionDate: $validated['transaction_date'],
                reference:       $validated['reference'] ?? null,
                description:     $validated['description'] ?? null,
                actorId:         $request->user()?->id,
            );
            return response()->json($tx, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function reconcileBank(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'       => 'required|string',
            'bank_account_id'   => 'required|uuid',
            'period_start'      => 'required|date',
            'period_end'        => 'required|date|after_or_equal:period_start',
            'statement_balance' => 'required|numeric',
            'notes'             => 'nullable|string',
        ]);

        try {
            $rec = $this->financeService->reconcileBank(
                tenantSlug:       $validated['tenant_slug'],
                bankAccountId:    $validated['bank_account_id'],
                periodStart:      $validated['period_start'],
                periodEnd:        $validated['period_end'],
                statementBalance: (float) $validated['statement_balance'],
                notes:            $validated['notes'] ?? null,
                actorId:          $request->user()?->id,
            );
            return response()->json($rec, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function getBankBalance(Request $request, string $id): JsonResponse
    {
        try {
            $balance = $this->financeService->getBankBalance($id);
            return response()->json(['bank_account_id' => $id, 'balance' => $balance]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Bank account not found.'], Response::HTTP_NOT_FOUND);
        }
    }

    public function getCashPosition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
        ]);

        $position = $this->financeService->getCashPosition($validated['tenant_slug']);
        return response()->json($position);
    }

    // =========================================================================
    // CAP-180 — Client Billing & Invoicing
    // =========================================================================

    public function createInvoice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'    => 'required|string',
            'invoice_number' => 'required|string|max:50',
            'client_id'      => 'required|uuid',
            'invoice_date'   => 'required|date',
            'project_id'     => 'nullable|uuid',
            'contract_id'    => 'nullable|uuid',
            'due_date'       => 'nullable|date',
            'notes'          => 'nullable|string',
        ]);

        try {
            $invoice = $this->financeService->createInvoice(
                tenantSlug:    $validated['tenant_slug'],
                invoiceNumber: $validated['invoice_number'],
                clientId:      $validated['client_id'],
                invoiceDate:   $validated['invoice_date'],
                projectId:     $validated['project_id'] ?? null,
                contractId:    $validated['contract_id'] ?? null,
                dueDate:       $validated['due_date'] ?? null,
                notes:         $validated['notes'] ?? null,
                actorId:       $request->user()?->id,
            );
            return response()->json($invoice, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function addInvoiceItem(Request $request, string $invoiceId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'  => 'required|string',
            'description'  => 'required|string|max:500',
            'quantity'     => 'required|numeric|min:0.0001',
            'unit_price'   => 'required|numeric|min:0',
            'tax_rate'     => 'nullable|numeric|min:0|max:1',
        ]);

        try {
            $item = $this->financeService->addInvoiceItem(
                tenantSlug:  $validated['tenant_slug'],
                invoiceId:   $invoiceId,
                description: $validated['description'],
                quantity:    (float) $validated['quantity'],
                unitPrice:   (float) $validated['unit_price'],
                taxRate:     (float) ($validated['tax_rate'] ?? 0.0),
                actorId:     $request->user()?->id,
            );
            return response()->json($item, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function issueInvoice(Request $request, string $invoiceId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
        ]);

        try {
            $invoice = $this->financeService->issueInvoice(
                tenantSlug: $validated['tenant_slug'],
                invoiceId:  $invoiceId,
                actorId:    $request->user()?->id,
            );
            return response()->json($invoice);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function getInvoice(Request $request, string $id): JsonResponse
    {
        try {
            $invoice = $this->financeService->getInvoice($id);
            return response()->json($invoice->load('items'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Invoice not found.'], Response::HTTP_NOT_FOUND);
        }
    }

    // =========================================================================
    // CAP-181 — Receivables & Collections
    // =========================================================================

    public function recordCollection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'     => 'required|string',
            'receivable_id'   => 'required|uuid',
            'amount'          => 'required|numeric|min:0.0001',
            'collection_date' => 'required|date',
            'payment_method'  => 'required|in:CASH,BANK_TRANSFER,CHEQUE',
            'reference'       => 'nullable|string|max:100',
            'notes'           => 'nullable|string',
            'bank_account_id' => 'nullable|uuid',
            'cash_account_id' => 'nullable|uuid',
        ]);

        try {
            $collection = $this->financeService->recordCollection(
                tenantSlug:     $validated['tenant_slug'],
                receivableId:   $validated['receivable_id'],
                amount:         (float) $validated['amount'],
                collectionDate: $validated['collection_date'],
                paymentMethod:  $validated['payment_method'],
                reference:      $validated['reference'] ?? null,
                notes:          $validated['notes'] ?? null,
                actorId:        $request->user()?->id,
                bankAccountId:  $validated['bank_account_id'] ?? null,
                cashAccountId:  $validated['cash_account_id'] ?? null,
            );
            return response()->json($collection, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    // =========================================================================
    // WEB-001L — List Endpoints
    // =========================================================================

    /**
     * GET /api/v1/finance/invoices
     * List all invoices for the active tenant.
     * CAP-180 — Client Billing & Invoicing
     */
    public function listInvoices(Request $request): JsonResponse
    {
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        $invoices = $this->financeService->listInvoices($tenantSlug);
        return response()->json($invoices->toArray());
    }

    /**
     * GET /api/v1/finance/bank-accounts
     * List all bank accounts for the active tenant.
     * CAP-111 — Bank Management
     */
    public function listBankAccounts(Request $request): JsonResponse
    {
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        $accounts = $this->financeService->listBankAccounts($tenantSlug);
        return response()->json($accounts->toArray());
    }

    /**
     * GET /api/v1/finance/cash-accounts
     * List all cash accounts for the active tenant.
     * CAP-110 — Cash Management
     */
    public function listCashAccounts(Request $request): JsonResponse
    {
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        $accounts = $this->financeService->listCashAccounts($tenantSlug);
        return response()->json($accounts->toArray());
    }

    /**
     * GET /api/v1/finance/payments
     * List all payments for the active tenant.
     * CAP-181 — Receivables & Collections
     */
    public function listPayments(Request $request): JsonResponse
    {
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        $payments = $this->financeService->listPayments($tenantSlug);
        return response()->json($payments->toArray());
    }

    /**
     * GET /api/v1/finance/collections
     * List all collections for the active tenant.
     * CAP-181 — Receivables & Collections
     */
    public function listCollections(Request $request): JsonResponse
    {
        $tenantSlug = app(\App\Foundation\TenantContext::class)->slug;
        $collections = $this->financeService->listCollections($tenantSlug);
        return response()->json($collections->toArray());
    }

    public function recordPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'     => 'required|string',
            'payment_number'  => 'required|string|max:50',
            'payment_type'    => 'required|in:SUPPLIER,CLIENT',
            'payment_method'  => 'required|in:CASH,BANK_TRANSFER,CHEQUE',
            'party_id'        => 'required|uuid',
            'party_type'      => 'required|in:supplier,client',
            'amount'          => 'required|numeric|min:0.0001',
            'payment_date'    => 'required|date',
            'invoice_id'      => 'nullable|uuid',
            'bank_account_id' => 'nullable|uuid',
            'reference'       => 'nullable|string|max:100',
            'notes'           => 'nullable|string',
        ]);

        try {
            $payment = $this->financeService->recordPayment(
                tenantSlug:     $validated['tenant_slug'],
                paymentNumber:  $validated['payment_number'],
                paymentType:    $validated['payment_type'],
                paymentMethod:  $validated['payment_method'],
                partyId:        $validated['party_id'],
                partyType:      $validated['party_type'],
                amount:         (float) $validated['amount'],
                paymentDate:    $validated['payment_date'],
                invoiceId:      $validated['invoice_id'] ?? null,
                bankAccountId:  $validated['bank_account_id'] ?? null,
                reference:      $validated['reference'] ?? null,
                notes:          $validated['notes'] ?? null,
                actorId:        $request->user()?->id,
            );
            return response()->json($payment, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
