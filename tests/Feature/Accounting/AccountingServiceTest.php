<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Accounting\Account;
use App\Accounting\AccountingPeriod;
use App\Accounting\AccountingService;
use App\Accounting\Events\JournalEntryPosted;
use App\Accounting\Events\PeriodClosed;
use App\Accounting\JournalEntry;
use App\Accounting\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * AccountingServiceTest — Feature tests for Accounting Application/Service Layer.
 * TASK-014B — Accounting Application/Service Layer
 */
class AccountingServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $service;
    private string $tenantSlug = 'accounting-svc-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountingService::class);
    }

    // =========================================================================
    // CAP-120 — Chart of Accounts
    // =========================================================================

    /** @test */
    public function create_account_persists_and_returns_model(): void
    {
        $account = $this->service->createAccount(
            tenantSlug:   $this->tenantSlug,
            accountCode:  '1000',
            name:         'Cash and Cash Equivalents',
            accountType:  'ASSET',
        );

        $this->assertNotNull($account->id);
        $this->assertSame('1000', $account->account_code);
        $this->assertSame('ASSET', $account->account_type);
        $this->assertSame('active', $account->status);
    }

    /** @test */
    public function create_account_rejects_invalid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createAccount($this->tenantSlug, '9999', 'Invalid', 'INVALID_TYPE');
    }

    /** @test */
    public function create_account_rejects_duplicate_code(): void
    {
        $this->service->createAccount($this->tenantSlug, '1001', 'First', 'ASSET');
        $this->expectException(InvalidArgumentException::class);
        $this->service->createAccount($this->tenantSlug, '1001', 'Second', 'ASSET');
    }

    /** @test */
    public function deactivate_account_changes_status(): void
    {
        $account = $this->service->createAccount($this->tenantSlug, '1002', 'Deactivate Test', 'ASSET');
        $deactivated = $this->service->deactivateAccount($this->tenantSlug, $account->id);
        $this->assertSame('inactive', $deactivated->status);
        $this->assertFalse($deactivated->isActive());
    }

    // =========================================================================
    // CAP-120 — Accounting Periods
    // =========================================================================

    /** @test */
    public function create_period_persists_as_open(): void
    {
        $period = $this->service->createPeriod(
            tenantSlug:  $this->tenantSlug,
            periodName:  '2026-10',
            startDate:   '2026-10-01',
            endDate:     '2026-10-31',
        );

        $this->assertNotNull($period->id);
        $this->assertSame('OPEN', $period->status);
        $this->assertTrue($period->isOpen());
    }

    /** @test */
    public function create_period_rejects_duplicate_name(): void
    {
        $this->service->createPeriod($this->tenantSlug, '2026-10-DUP', '2026-10-01', '2026-10-31');
        $this->expectException(InvalidArgumentException::class);
        $this->service->createPeriod($this->tenantSlug, '2026-10-DUP', '2026-10-01', '2026-10-31');
    }

    /** @test */
    public function close_period_changes_status_and_fires_event(): void
    {
        Event::fake([PeriodClosed::class]);

        $period = $this->service->createPeriod($this->tenantSlug, '2026-09', '2026-09-01', '2026-09-30');
        $closed = $this->service->closePeriod($this->tenantSlug, $period->id);

        $this->assertSame('CLOSED', $closed->status);
        $this->assertTrue($closed->isClosed());
        Event::assertDispatched(PeriodClosed::class);
    }

    /** @test */
    public function cannot_close_already_closed_period(): void
    {
        Event::fake([PeriodClosed::class]);
        $period = $this->service->createPeriod($this->tenantSlug, '2026-08', '2026-08-01', '2026-08-31');
        $this->service->closePeriod($this->tenantSlug, $period->id);

        $this->expectException(InvalidArgumentException::class);
        $this->service->closePeriod($this->tenantSlug, $period->id);
    }

    /** @test */
    public function is_period_open_returns_correct_value(): void
    {
        $period = $this->service->createPeriod($this->tenantSlug, '2026-07', '2026-07-01', '2026-07-31');
        $this->assertTrue($this->service->isPeriodOpen($period->id));

        Event::fake([PeriodClosed::class]);
        $this->service->closePeriod($this->tenantSlug, $period->id);
        $this->assertFalse($this->service->isPeriodOpen($period->id));
    }

    // =========================================================================
    // CAP-121 — Journal Entries
    // =========================================================================

    private function makeAccounts(): array
    {
        $debitAccount = $this->service->createAccount($this->tenantSlug, '1100', 'Accounts Receivable', 'ASSET');
        $creditAccount = $this->service->createAccount($this->tenantSlug, '4000', 'Revenue', 'REVENUE');
        return [$debitAccount, $creditAccount];
    }

    private function makePeriod(string $name = '2026-10-SVC'): AccountingPeriod
    {
        return $this->service->createPeriod($this->tenantSlug, $name, '2026-10-01', '2026-10-31');
    }

    /** @test */
    public function create_journal_entry_persists_as_draft_with_lines(): void
    {
        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod();

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-SVC-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 1000.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 1000.0],
            ],
        );

        $this->assertSame('DRAFT', $entry->status);
        $this->assertCount(2, JournalLine::where('entry_id', $entry->id)->get());
    }

    /** @test */
    public function create_journal_entry_rejects_single_line(): void
    {
        [$debitAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-SVC2');

        $this->expectException(InvalidArgumentException::class);
        $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-SINGLE',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 500.0, 'credit' => 0.0],
            ],
        );
    }

    /** @test */
    public function post_journal_entry_changes_status_and_fires_event(): void
    {
        Event::fake([JournalEntryPosted::class]);

        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-POST');

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-POST-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 500.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 500.0],
            ],
        );

        $posted = $this->service->postJournalEntry($this->tenantSlug, $entry->id);

        $this->assertSame('POSTED', $posted->status);
        $this->assertNotNull($posted->posted_at);
        Event::assertDispatched(JournalEntryPosted::class);
    }

    /** @test */
    public function post_rejects_unbalanced_entry(): void
    {
        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-UNBAL');

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-UNBAL-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 1000.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 800.0],
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);
    }

    /** @test */
    public function post_rejects_entry_in_closed_period(): void
    {
        Event::fake([PeriodClosed::class]);

        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-CLOSED');
        $this->service->closePeriod($this->tenantSlug, $period->id);

        $entry = JournalEntry::create([
            'reference'    => 'JE-CLOSED-001',
            'posting_date' => '2026-10-03',
            'period_id'    => $period->id,
            'status'       => 'DRAFT',
            'tenant_slug'  => $this->tenantSlug,
        ]);
        JournalLine::create(['entry_id' => $entry->id, 'account_id' => $debitAcc->id, 'debit' => 100, 'credit' => 0, 'tenant_slug' => $this->tenantSlug]);
        JournalLine::create(['entry_id' => $entry->id, 'account_id' => $creditAcc->id, 'debit' => 0, 'credit' => 100, 'tenant_slug' => $this->tenantSlug]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);
    }

    /** @test */
    public function cannot_post_already_posted_entry(): void
    {
        Event::fake([JournalEntryPosted::class]);

        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-DBLPOST');

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-DBLPOST-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 200.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 200.0],
            ],
        );
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);

        $this->expectException(InvalidArgumentException::class);
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);
    }

    /** @test */
    public function reverse_journal_entry_creates_opposite_entry(): void
    {
        Event::fake([JournalEntryPosted::class]);

        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-REV');

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-REV-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 750.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 750.0],
            ],
        );
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);

        $reversal = $this->service->reverseJournalEntry(
            tenantSlug:   $this->tenantSlug,
            entryId:      $entry->id,
            reversalDate: '2026-10-04',
            periodId:     $period->id,
        );

        $this->assertSame('POSTED', $reversal->status);
        $this->assertSame($entry->id, $reversal->reversal_of);

        $entry->refresh();
        $this->assertSame('REVERSED', $entry->status);

        // Reversal lines should swap debit/credit
        $reversalLines = JournalLine::where('entry_id', $reversal->id)->get();
        $this->assertCount(2, $reversalLines);
    }

    // =========================================================================
    // Public Capabilities
    // =========================================================================

    /** @test */
    public function get_account_balance_returns_correct_balance(): void
    {
        Event::fake([JournalEntryPosted::class]);

        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-BAL');

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-BAL-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 2000.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 2000.0],
            ],
        );
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);

        $balance = $this->service->getAccountBalance($debitAcc->id);
        $this->assertEquals(2000.0, $balance['total_debit']);
        $this->assertEquals(2000.0, $balance['balance']); // ASSET: debit - credit
    }

    /** @test */
    public function get_trial_balance_returns_balanced_result(): void
    {
        Event::fake([JournalEntryPosted::class]);

        [$debitAcc, $creditAcc] = $this->makeAccounts();
        $period = $this->makePeriod('2026-10-TB');

        $entry = $this->service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-TB-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 3000.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 3000.0],
            ],
        );
        $this->service->postJournalEntry($this->tenantSlug, $entry->id);

        $tb = $this->service->getTrialBalance($this->tenantSlug);
        $this->assertTrue($tb['is_balanced']);
        $this->assertEquals($tb['total_debit'], $tb['total_credit']);
    }

    /** @test */
    public function create_posting_rule_persists(): void
    {
        [$debitAcc, $creditAcc] = $this->makeAccounts();

        $rule = $this->service->createPostingRule(
            tenantSlug:      $this->tenantSlug,
            triggerEvent:    'InvoiceIssued',
            ruleName:        'Invoice Revenue Recognition',
            debitAccountId:  $debitAcc->id,
            creditAccountId: $creditAcc->id,
            amountSource:    'totalAmount',
        );

        $this->assertNotNull($rule->id);
        $this->assertSame('InvoiceIssued', $rule->trigger_event);
        $this->assertTrue($rule->isActive());
    }
}
