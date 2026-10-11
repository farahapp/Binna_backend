<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Audit\AuditService;
use App\Accounting\Events\JournalEntryPosted;
use App\Accounting\Events\PeriodClosed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * AccountingService — Application/Service Layer for Accounting module.
 *
 * Architectural constraints (TASK-014B / A1 MOD-14):
 *  - Implements Accounting use cases: CAP-120 (CoA + Periods), CAP-121 (Journal Entries + Posting Rules).
 *  - Dispatches Domain Events: JournalEntryPosted, PeriodClosed.
 *  - Integrates with AuditService per established conventions.
 *  - Tenant context resolved by caller via tenant_slug — never cross-tenant access.
 *  - No tenant_id — isolation is physical (Schema-per-Tenant) per OD-001.
 *  - No Auth::user() in business logic — actorId passed as parameter.
 *  - Journal Entries are IMMUTABLE after posting (DEC-013).
 *  - Balanced entry invariant: Sum(debit) = Sum(credit) for every posted entry.
 *  - MUST NOT call Finance/Inventory/HR/Subcontracts/Equipment directly.
 *  - MUST NOT own Invoices, Payments, or Bank Operations.
 *
 * TASK-014B — Accounting Application/Service Layer
 */
class AccountingService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // =========================================================================
    // CAP-120 — Chart of Accounts & Accounting Setup
    // =========================================================================

    public function createAccount(
        string $tenantSlug,
        string $accountCode,
        string $name,
        string $accountType,
        string|null $parentId = null,
        bool $isControlAccount = false,
        string|null $description = null,
        string|null $actorId = null,
    ): Account {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($accountCode)) throw new InvalidArgumentException('account_code is required.');
        if (empty($name)) throw new InvalidArgumentException('name is required.');

        $validTypes = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'];
        if (!in_array($accountType, $validTypes, true)) {
            throw new InvalidArgumentException("account_type must be one of: " . implode(', ', $validTypes));
        }

        if (Account::where('account_code', $accountCode)->exists()) {
            throw new InvalidArgumentException("account_code already exists: {$accountCode}");
        }

        $account = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $accountCode, $name, $accountType, $parentId, $isControlAccount, $description
        ) {
            return Account::create([
                'account_code'      => $accountCode,
                'name'              => $name,
                'account_type'      => $accountType,
                'parent_id'         => $parentId,
                'is_control_account' => $isControlAccount,
                'description'       => $description,
                'status'            => 'active',
                'tenant_slug'       => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'accounting.account.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Account::class,
                'subject_id'   => $account->id,
                'after'        => ['account_code' => $accountCode, 'account_type' => $accountType],
            ]
        );

        return $account;
    }

    public function deactivateAccount(
        string $tenantSlug,
        string $accountId,
        string|null $actorId = null,
    ): Account {
        $account = Account::findOrFail($accountId);

        DB::connection('tenant')->transaction(function () use ($account) {
            $account->update(['status' => 'inactive']);
        });

        $this->auditService->logEvent(
            'accounting.account.deactivated',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Account::class,
                'subject_id'   => $accountId,
                'after'        => ['status' => 'inactive'],
            ]
        );

        $account->refresh();
        return $account;
    }

    // =========================================================================
    // CAP-120 — Accounting Periods
    // =========================================================================

    public function createPeriod(
        string $tenantSlug,
        string $periodName,
        string $startDate,
        string $endDate,
        string|null $actorId = null,
    ): AccountingPeriod {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($periodName)) throw new InvalidArgumentException('period_name is required.');

        if (AccountingPeriod::where('period_name', $periodName)->exists()) {
            throw new InvalidArgumentException("period_name already exists: {$periodName}");
        }

        $period = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $periodName, $startDate, $endDate
        ) {
            return AccountingPeriod::create([
                'period_name' => $periodName,
                'start_date'  => $startDate,
                'end_date'    => $endDate,
                'status'      => 'OPEN',
                'tenant_slug' => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'accounting.period.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => AccountingPeriod::class,
                'subject_id'   => $period->id,
                'after'        => ['period_name' => $periodName, 'status' => 'OPEN'],
            ]
        );

        return $period;
    }

    public function closePeriod(
        string $tenantSlug,
        string $periodId,
        string|null $actorId = null,
    ): AccountingPeriod {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');

        $period = AccountingPeriod::findOrFail($periodId);

        if (!$period->isOpen()) {
            throw new InvalidArgumentException("Period '{$period->period_name}' is not OPEN — cannot close.");
        }

        DB::connection('tenant')->transaction(function () use ($period, $actorId) {
            $period->update([
                'status'     => 'CLOSED',
                'closed_by'  => $actorId,
                'closed_at'  => now(),
            ]);
        });

        $this->auditService->logEvent(
            'accounting.period.closed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => AccountingPeriod::class,
                'subject_id'   => $periodId,
                'after'        => ['status' => 'CLOSED'],
            ]
        );

        Event::dispatch(new PeriodClosed(
            periodId:   $period->id,
            periodName: $period->period_name,
            tenantSlug: $tenantSlug,
        ));

        $period->refresh();
        return $period;
    }

    // =========================================================================
    // CAP-121 — Journal Entries & Posting Rules
    // =========================================================================

    /**
     * Create a DRAFT journal entry with its lines.
     *
     * @param array<int, array{account_id: string, debit: float, credit: float, description?: string|null, project_id?: string|null, cost_code_id?: string|null}> $lines
     */
    public function createJournalEntry(
        string $tenantSlug,
        string $reference,
        string $postingDate,
        string $periodId,
        array $lines,
        string|null $sourceModule = null,
        string|null $sourceDocumentId = null,
        string|null $sourceDocumentType = null,
        string|null $narration = null,
        string|null $actorId = null,
    ): JournalEntry {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($reference)) throw new InvalidArgumentException('reference is required.');
        if (count($lines) < 2) throw new InvalidArgumentException('A journal entry requires at least 2 lines.');

        if (JournalEntry::where('reference', $reference)->exists()) {
            throw new InvalidArgumentException("reference already exists: {$reference}");
        }

        // Validate lines structure
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            if (empty($line['account_id'])) throw new InvalidArgumentException('Each line must have an account_id.');
            $totalDebit += (float)($line['debit'] ?? 0);
            $totalCredit += (float)($line['credit'] ?? 0);
        }

        $entry = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $reference, $postingDate, $periodId, $lines,
            $sourceModule, $sourceDocumentId, $sourceDocumentType, $narration, $actorId
        ) {
            $entry = JournalEntry::create([
                'reference'            => $reference,
                'source_module'        => $sourceModule,
                'source_document_id'   => $sourceDocumentId,
                'source_document_type' => $sourceDocumentType,
                'posting_date'         => $postingDate,
                'period_id'            => $periodId,
                'status'               => 'DRAFT',
                'narration'            => $narration,
                'created_by'           => $actorId,
                'tenant_slug'          => $tenantSlug,
            ]);

            foreach ($lines as $line) {
                JournalLine::create([
                    'entry_id'      => $entry->id,
                    'account_id'    => $line['account_id'],
                    'debit'         => (float)($line['debit'] ?? 0),
                    'credit'        => (float)($line['credit'] ?? 0),
                    'project_id'    => $line['project_id'] ?? null,
                    'cost_code_id'  => $line['cost_code_id'] ?? null,
                    'department_id' => $line['department_id'] ?? null,
                    'description'   => $line['description'] ?? null,
                    'tenant_slug'   => $tenantSlug,
                ]);
            }

            return $entry;
        });

        $this->auditService->logEvent(
            'accounting.journal_entry.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => JournalEntry::class,
                'subject_id'   => $entry->id,
                'after'        => ['reference' => $reference, 'status' => 'DRAFT'],
            ]
        );

        return $entry;
    }

    /**
     * Post a DRAFT journal entry to the General Ledger.
     * Validates balanced entry invariant before posting.
     */
    public function postJournalEntry(
        string $tenantSlug,
        string $entryId,
        string|null $actorId = null,
    ): JournalEntry {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');

        $entry = JournalEntry::findOrFail($entryId);

        if (!$entry->isDraft()) {
            throw new InvalidArgumentException("Only DRAFT entries can be posted. Current status: {$entry->status}");
        }

        // Validate period is open
        $period = AccountingPeriod::findOrFail($entry->period_id);
        if (!$period->isOpen()) {
            throw new InvalidArgumentException("Cannot post to a non-OPEN period: {$period->period_name}");
        }

        // Validate balanced entry invariant
        $lines = JournalLine::where('entry_id', $entryId)->get();
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot post a journal entry with no lines.');
        }

        $totalDebit = $lines->sum(fn($l) => (float)$l->debit);
        $totalCredit = $lines->sum(fn($l) => (float)$l->credit);

        if (abs($totalDebit - $totalCredit) > 0.0001) {
            throw new InvalidArgumentException(
                "Journal entry is not balanced. Debit: {$totalDebit}, Credit: {$totalCredit}"
            );
        }

        if ($totalDebit <= 0) {
            throw new InvalidArgumentException('Journal entry must have positive debit/credit amounts.');
        }

        DB::connection('tenant')->transaction(function () use ($entry, $actorId) {
            $entry->update([
                'status'    => 'POSTED',
                'posted_at' => now(),
            ]);
        });

        $this->auditService->logEvent(
            'accounting.journal_entry.posted',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => JournalEntry::class,
                'subject_id'   => $entryId,
                'after'        => ['status' => 'POSTED', 'total_debit' => $totalDebit],
            ]
        );

        Event::dispatch(new JournalEntryPosted(
            entryId:    $entry->id,
            reference:  $entry->reference,
            periodId:   $entry->period_id,
            totalDebit: $totalDebit,
            tenantSlug: $tenantSlug,
        ));

        $entry->refresh();
        return $entry;
    }

    /**
     * Reverse a POSTED journal entry by creating an equal and opposite entry.
     */
    public function reverseJournalEntry(
        string $tenantSlug,
        string $entryId,
        string $reversalDate,
        string $periodId,
        string|null $narration = null,
        string|null $actorId = null,
    ): JournalEntry {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');

        $original = JournalEntry::findOrFail($entryId);

        if (!$original->isPosted()) {
            throw new InvalidArgumentException('Only POSTED entries can be reversed.');
        }

        $period = AccountingPeriod::findOrFail($periodId);
        if (!$period->isOpen()) {
            throw new InvalidArgumentException("Cannot post reversal to a non-OPEN period: {$period->period_name}");
        }

        $originalLines = JournalLine::where('entry_id', $entryId)->get();

        $reversalEntry = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $original, $originalLines, $reversalDate, $periodId, $narration, $actorId, $entryId
        ) {
            // Mark original as REVERSED
            $original->update(['status' => 'REVERSED']);

            // Create reversal entry
            $reversal = JournalEntry::create([
                'reference'            => 'REV-' . $original->reference,
                'source_module'        => 'Manual',
                'source_document_id'   => $entryId,
                'source_document_type' => 'JournalEntry',
                'posting_date'         => $reversalDate,
                'period_id'            => $periodId,
                'status'               => 'POSTED',
                'narration'            => $narration ?? "Reversal of {$original->reference}",
                'created_by'           => $actorId,
                'posted_at'            => now(),
                'reversal_of'          => $entryId,
                'tenant_slug'          => $tenantSlug,
            ]);

            // Create reversed lines (swap debit/credit)
            foreach ($originalLines as $line) {
                JournalLine::create([
                    'entry_id'      => $reversal->id,
                    'account_id'    => $line->account_id,
                    'debit'         => (float)$line->credit,
                    'credit'        => (float)$line->debit,
                    'project_id'    => $line->project_id,
                    'cost_code_id'  => $line->cost_code_id,
                    'department_id' => $line->department_id,
                    'description'   => $line->description,
                    'tenant_slug'   => $tenantSlug,
                ]);
            }

            return $reversal;
        });

        $this->auditService->logEvent(
            'accounting.journal_entry.reversed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => JournalEntry::class,
                'subject_id'   => $reversalEntry->id,
                'after'        => ['reversal_of' => $entryId, 'status' => 'POSTED'],
            ]
        );

        return $reversalEntry;
    }

    // =========================================================================
    // Posting Rules
    // =========================================================================

    public function createPostingRule(
        string $tenantSlug,
        string $triggerEvent,
        string $ruleName,
        string|null $debitAccountId = null,
        string|null $creditAccountId = null,
        string|null $amountSource = null,
        string|null $description = null,
        string|null $actorId = null,
    ): PostingRule {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($triggerEvent)) throw new InvalidArgumentException('trigger_event is required.');
        if (empty($ruleName)) throw new InvalidArgumentException('rule_name is required.');

        $rule = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $triggerEvent, $ruleName, $debitAccountId, $creditAccountId, $amountSource, $description
        ) {
            return PostingRule::create([
                'trigger_event'    => $triggerEvent,
                'rule_name'        => $ruleName,
                'debit_account_id' => $debitAccountId,
                'credit_account_id' => $creditAccountId,
                'amount_source'    => $amountSource,
                'is_active'        => true,
                'description'      => $description,
                'tenant_slug'      => $tenantSlug,
            ]);
        });

        $this->auditService->logEvent(
            'accounting.posting_rule.created',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => PostingRule::class,
                'subject_id'   => $rule->id,
                'after'        => ['trigger_event' => $triggerEvent, 'rule_name' => $ruleName],
            ]
        );

        return $rule;
    }

    // =========================================================================
    // Public Capabilities (A1 MOD-14 Contracts)
    // =========================================================================

    /**
     * Get the balance of an account for a given period.
     * Balance = Sum(debit lines) - Sum(credit lines) for ASSET/EXPENSE accounts.
     * Balance = Sum(credit lines) - Sum(debit lines) for LIABILITY/EQUITY/REVENUE accounts.
     */
    public function getAccountBalance(string $accountId, string|null $periodId = null): array
    {
        $account = Account::findOrFail($accountId);

        $query = JournalLine::where('account_id', $accountId)
            ->whereHas('entry', fn($q) => $q->where('status', 'POSTED'));

        if ($periodId) {
            $query->whereHas('entry', fn($q) => $q->where('period_id', $periodId));
        }

        $totalDebit = (float) $query->sum('debit');
        $totalCredit = (float) $query->sum('credit');

        // Normal balance direction
        $normalBalance = in_array($account->account_type, ['ASSET', 'EXPENSE'], true)
            ? $totalDebit - $totalCredit
            : $totalCredit - $totalDebit;

        return [
            'account_id'     => $accountId,
            'account_code'   => $account->account_code,
            'account_type'   => $account->account_type,
            'total_debit'    => $totalDebit,
            'total_credit'   => $totalCredit,
            'balance'        => $normalBalance,
        ];
    }

    /**
     * Get trial balance — all accounts with their balances.
     */
    public function getTrialBalance(string $tenantSlug, string|null $periodId = null): array
    {
        $accounts = Account::where('tenant_slug', $tenantSlug)
            ->where('status', 'active')
            ->get();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $balance = $this->getAccountBalance($account->id, $periodId);
            if ($balance['total_debit'] > 0 || $balance['total_credit'] > 0) {
                $rows[] = $balance;
                $totalDebit += $balance['total_debit'];
                $totalCredit += $balance['total_credit'];
            }
        }

        return [
            'period_id'    => $periodId,
            'tenant_slug'  => $tenantSlug,
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced'  => abs($totalDebit - $totalCredit) < 0.0001,
        ];
    }

    /**
     * Check if a period is open — consumed by Finance and HR/Payroll.
     */
    public function isPeriodOpen(string $periodId): bool
    {
        $period = AccountingPeriod::find($periodId);
        return $period !== null && $period->isOpen();
    }

    public function getPeriod(string $periodId): AccountingPeriod
    {
        return AccountingPeriod::findOrFail($periodId);
    }

    public function getJournalEntry(string $entryId): JournalEntry
    {
        return JournalEntry::with('lines')->findOrFail($entryId);
    }

    // =========================================================================
    // WEB-001N — List Methods (read-only, paginated)
    // =========================================================================

    /**
     * Paginated list of Accounts in the active tenant schema.
     * WEB-001N — OI-1 resolved.
     */
    public function listAccounts(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Account::orderBy('account_code')->paginate($perPage);
    }

    /**
     * Paginated list of Journal Entries in the active tenant schema.
     * WEB-001N — OI-2 resolved.
     */
    public function listJournalEntries(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return JournalEntry::with('lines')->orderByDesc('posting_date')->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Paginated list of Accounting Periods in the active tenant schema.
     * WEB-001N — OI-3 resolved.
     */
    public function listPeriods(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return AccountingPeriod::orderByDesc('start_date')->paginate($perPage);
    }

    /**
     * Paginated list of Posting Rules in the active tenant schema.
     * WEB-001N — OI-4 resolved.
     */
    public function listPostingRules(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return PostingRule::orderBy('trigger_event')->paginate($perPage);
    }
}
