<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Accounting\AccountingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * AccountingController — HTTP API Layer for Accounting module.
 *
 * Thin controller — all business logic delegated to AccountingService.
 * Architectural constraints (TASK-014C / A1 MOD-14):
 *  - CAP-120: Chart of Accounts + Accounting Periods endpoints
 *  - CAP-121: Journal Entries + Posting Rules endpoints
 *
 * TASK-014C — Accounting HTTP API Layer
 */
class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    // =========================================================================
    // CAP-120 — Chart of Accounts
    // =========================================================================

    public function createAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'       => 'required|string',
            'account_code'      => 'required|string|max:50',
            'name'              => 'required|string|max:255',
            'account_type'      => 'required|in:ASSET,LIABILITY,EQUITY,REVENUE,EXPENSE',
            'parent_id'         => 'nullable|uuid',
            'is_control_account' => 'nullable|boolean',
            'description'       => 'nullable|string',
        ]);

        try {
            $account = $this->accountingService->createAccount(
                tenantSlug:       $validated['tenant_slug'],
                accountCode:      $validated['account_code'],
                name:             $validated['name'],
                accountType:      $validated['account_type'],
                parentId:         $validated['parent_id'] ?? null,
                isControlAccount: (bool)($validated['is_control_account'] ?? false),
                description:      $validated['description'] ?? null,
                actorId:          $request->user()?->id,
            );
            return response()->json($account, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function deactivateAccount(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
        ]);

        try {
            $account = $this->accountingService->deactivateAccount(
                tenantSlug: $validated['tenant_slug'],
                accountId:  $id,
                actorId:    $request->user()?->id,
            );
            return response()->json($account);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function getAccountBalance(Request $request, string $id): JsonResponse
    {
        $periodId = $request->query('period_id');

        try {
            $balance = $this->accountingService->getAccountBalance($id, $periodId ?: null);
            return response()->json($balance);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Account not found.'], Response::HTTP_NOT_FOUND);
        }
    }

    public function getTrialBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
            'period_id'   => 'nullable|uuid',
        ]);

        $result = $this->accountingService->getTrialBalance(
            tenantSlug: $validated['tenant_slug'],
            periodId:   $validated['period_id'] ?? null,
        );

        return response()->json($result);
    }

    // =========================================================================
    // CAP-120 — Accounting Periods
    // =========================================================================

    public function createPeriod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
            'period_name' => 'required|string|max:20',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
        ]);

        try {
            $period = $this->accountingService->createPeriod(
                tenantSlug:  $validated['tenant_slug'],
                periodName:  $validated['period_name'],
                startDate:   $validated['start_date'],
                endDate:     $validated['end_date'],
                actorId:     $request->user()?->id,
            );
            return response()->json($period, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function closePeriod(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
        ]);

        try {
            $period = $this->accountingService->closePeriod(
                tenantSlug: $validated['tenant_slug'],
                periodId:   $id,
                actorId:    $request->user()?->id,
            );
            return response()->json($period);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function isPeriodOpen(Request $request, string $id): JsonResponse
    {
        $isOpen = $this->accountingService->isPeriodOpen($id);
        return response()->json(['period_id' => $id, 'is_open' => $isOpen]);
    }

    // =========================================================================
    // CAP-121 — Journal Entries
    // =========================================================================

    public function createJournalEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'          => 'required|string',
            'reference'            => 'required|string|max:100',
            'posting_date'         => 'required|date',
            'period_id'            => 'required|uuid',
            'narration'            => 'nullable|string',
            'source_module'        => 'nullable|string|max:50',
            'source_document_id'   => 'nullable|uuid',
            'source_document_type' => 'nullable|string|max:100',
            'lines'                => 'required|array|min:2',
            'lines.*.account_id'   => 'required|uuid',
            'lines.*.debit'        => 'nullable|numeric|min:0',
            'lines.*.credit'       => 'nullable|numeric|min:0',
            'lines.*.description'  => 'nullable|string',
            'lines.*.project_id'   => 'nullable|uuid',
            'lines.*.cost_code_id' => 'nullable|uuid',
        ]);

        try {
            $entry = $this->accountingService->createJournalEntry(
                tenantSlug:          $validated['tenant_slug'],
                reference:           $validated['reference'],
                postingDate:         $validated['posting_date'],
                periodId:            $validated['period_id'],
                lines:               $validated['lines'],
                sourceModule:        $validated['source_module'] ?? null,
                sourceDocumentId:    $validated['source_document_id'] ?? null,
                sourceDocumentType:  $validated['source_document_type'] ?? null,
                narration:           $validated['narration'] ?? null,
                actorId:             $request->user()?->id,
            );
            return response()->json($entry->load('lines'), Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function postJournalEntry(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'required|string',
        ]);

        try {
            $entry = $this->accountingService->postJournalEntry(
                tenantSlug: $validated['tenant_slug'],
                entryId:    $id,
                actorId:    $request->user()?->id,
            );
            return response()->json($entry->load('lines'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function reverseJournalEntry(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'   => 'required|string',
            'reversal_date' => 'required|date',
            'period_id'     => 'required|uuid',
            'narration'     => 'nullable|string',
        ]);

        try {
            $reversal = $this->accountingService->reverseJournalEntry(
                tenantSlug:   $validated['tenant_slug'],
                entryId:      $id,
                reversalDate: $validated['reversal_date'],
                periodId:     $validated['period_id'],
                narration:    $validated['narration'] ?? null,
                actorId:      $request->user()?->id,
            );
            return response()->json($reversal->load('lines'), Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function getJournalEntry(Request $request, string $id): JsonResponse
    {
        try {
            $entry = $this->accountingService->getJournalEntry($id);
            return response()->json($entry);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Journal entry not found.'], Response::HTTP_NOT_FOUND);
        }
    }

    // =========================================================================
    // CAP-121 — Posting Rules
    // =========================================================================

    public function createPostingRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug'       => 'required|string',
            'trigger_event'     => 'required|string|max:100',
            'rule_name'         => 'required|string|max:200',
            'debit_account_id'  => 'nullable|uuid',
            'credit_account_id' => 'nullable|uuid',
            'amount_source'     => 'nullable|string|max:100',
            'description'       => 'nullable|string',
        ]);

        try {
            $rule = $this->accountingService->createPostingRule(
                tenantSlug:      $validated['tenant_slug'],
                triggerEvent:    $validated['trigger_event'],
                ruleName:        $validated['rule_name'],
                debitAccountId:  $validated['debit_account_id'] ?? null,
                creditAccountId: $validated['credit_account_id'] ?? null,
                amountSource:    $validated['amount_source'] ?? null,
                description:     $validated['description'] ?? null,
                actorId:         $request->user()?->id,
            );
            return response()->json($rule, Response::HTTP_CREATED);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    // =========================================================================
    // WEB-001N — List Endpoints
    // =========================================================================

    /**
     * GET /api/v1/accounting/accounts
     * Paginated list of Chart-of-Accounts entries for the active tenant.
     * WEB-001N — OI-1 resolved.
     */
    public function listAccounts(Request $request): JsonResponse
    {
        $result = $this->accountingService->listAccounts();

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/accounting/journal-entries
     * Paginated list of Journal Entries for the active tenant.
     * WEB-001N — OI-2 resolved.
     */
    public function listJournalEntries(Request $request): JsonResponse
    {
        $result = $this->accountingService->listJournalEntries();

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/accounting/periods
     * Paginated list of Accounting Periods for the active tenant.
     * WEB-001N — OI-3 resolved.
     */
    public function listPeriods(Request $request): JsonResponse
    {
        $result = $this->accountingService->listPeriods();

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/accounting/posting-rules
     * Paginated list of Posting Rules for the active tenant.
     * WEB-001N — OI-4 resolved.
     */
    public function listPostingRules(Request $request): JsonResponse
    {
        $result = $this->accountingService->listPostingRules();

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ]);
    }
}
