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
use App\Accounting\PostingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AccountingFoundationTest — Feature tests for Accounting Domain/Data Foundation.
 * TASK-014A — Accounting Domain/Data Foundation
 * A1 MOD-14 — CAP-120/121
 */
class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function accounts_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('accounts'),
            'accounts table must exist in the tenant schema'
        );
    }

    /** @test */
    public function accounting_periods_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('accounting_periods'),
            'accounting_periods table must exist in the tenant schema'
        );
    }

    /** @test */
    public function journal_entries_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('journal_entries'),
            'journal_entries table must exist in the tenant schema'
        );
    }

    /** @test */
    public function journal_lines_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('journal_lines'),
            'journal_lines table must exist in the tenant schema'
        );
    }

    /** @test */
    public function posting_rules_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('posting_rules'),
            'posting_rules table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation — No tenant_id (OD-001)
    // -------------------------------------------------------------------------

    /** @test */
    public function accounts_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('accounts', 'tenant_id'),
            'accounts must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function journal_entries_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('journal_entries', 'tenant_id'),
            'journal_entries must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function accounting_periods_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('accounting_periods', 'tenant_id'),
            'accounting_periods must NOT have a tenant_id column'
        );
    }

    // -------------------------------------------------------------------------
    // tenant_slug presence
    // -------------------------------------------------------------------------

    /** @test */
    public function accounts_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('accounts', 'tenant_slug'),
            'accounts must have tenant_slug for cross-schema traceability'
        );
    }

    /** @test */
    public function journal_entries_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('journal_entries', 'tenant_slug'),
            'journal_entries must have tenant_slug for cross-schema traceability'
        );
    }

    // -------------------------------------------------------------------------
    // Model connection
    // -------------------------------------------------------------------------

    /** @test */
    public function account_model_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new Account())->getConnectionName());
    }

    /** @test */
    public function accounting_period_model_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new AccountingPeriod())->getConnectionName());
    }

    /** @test */
    public function journal_entry_model_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new JournalEntry())->getConnectionName());
    }

    /** @test */
    public function journal_line_model_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new JournalLine())->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // UUID PK
    // -------------------------------------------------------------------------

    /** @test */
    public function account_has_uuid_primary_key(): void
    {
        $account = Account::create([
            'account_code' => '1000',
            'name'         => 'Cash',
            'account_type' => 'ASSET',
            'tenant_slug'  => 'test-tenant',
        ]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $account->id
        );
    }

    /** @test */
    public function journal_entry_has_uuid_primary_key(): void
    {
        $period = AccountingPeriod::create([
            'period_name' => '2026-10',
            'start_date'  => '2026-10-01',
            'end_date'    => '2026-10-31',
            'tenant_slug' => 'test-tenant',
        ]);
        $entry = JournalEntry::create([
            'reference'    => 'JE-UUID-001',
            'posting_date' => '2026-10-03',
            'period_id'    => $period->id,
            'tenant_slug'  => 'test-tenant',
        ]);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $entry->id
        );
    }

    // -------------------------------------------------------------------------
    // Model defaults
    // -------------------------------------------------------------------------

    /** @test */
    public function account_defaults_to_active_status(): void
    {
        $account = Account::create([
            'account_code' => '2000',
            'name'         => 'Accounts Payable',
            'account_type' => 'LIABILITY',
            'tenant_slug'  => 'test-tenant',
        ]);
        $this->assertSame('active', $account->status);
        $this->assertFalse($account->is_control_account);
    }

    /** @test */
    public function accounting_period_defaults_to_open_status(): void
    {
        $period = AccountingPeriod::create([
            'period_name' => '2026-11',
            'start_date'  => '2026-11-01',
            'end_date'    => '2026-11-30',
            'tenant_slug' => 'test-tenant',
        ]);
        $this->assertSame('OPEN', $period->status);
        $this->assertTrue($period->isOpen());
        $this->assertFalse($period->isClosed());
        $this->assertFalse($period->isLocked());
    }

    /** @test */
    public function journal_entry_defaults_to_draft_status(): void
    {
        $period = AccountingPeriod::create([
            'period_name' => '2026-12',
            'start_date'  => '2026-12-01',
            'end_date'    => '2026-12-31',
            'tenant_slug' => 'test-tenant',
        ]);
        $entry = JournalEntry::create([
            'reference'    => 'JE-DRAFT-001',
            'posting_date' => '2026-10-03',
            'period_id'    => $period->id,
            'tenant_slug'  => 'test-tenant',
        ]);
        $this->assertSame('DRAFT', $entry->status);
        $this->assertTrue($entry->isDraft());
        $this->assertFalse($entry->isPosted());
        $this->assertFalse($entry->isReversed());
    }

    // -------------------------------------------------------------------------
    // Account type helpers
    // -------------------------------------------------------------------------

    /** @test */
    public function account_type_helpers_work_correctly(): void
    {
        $asset = Account::create(['account_code' => 'A1', 'name' => 'Asset', 'account_type' => 'ASSET', 'tenant_slug' => 'test-tenant']);
        $liability = Account::create(['account_code' => 'L1', 'name' => 'Liability', 'account_type' => 'LIABILITY', 'tenant_slug' => 'test-tenant']);
        $equity = Account::create(['account_code' => 'E1', 'name' => 'Equity', 'account_type' => 'EQUITY', 'tenant_slug' => 'test-tenant']);
        $revenue = Account::create(['account_code' => 'R1', 'name' => 'Revenue', 'account_type' => 'REVENUE', 'tenant_slug' => 'test-tenant']);
        $expense = Account::create(['account_code' => 'EX1', 'name' => 'Expense', 'account_type' => 'EXPENSE', 'tenant_slug' => 'test-tenant']);

        $this->assertTrue($asset->isAsset());
        $this->assertTrue($liability->isLiability());
        $this->assertTrue($equity->isEquity());
        $this->assertTrue($revenue->isRevenue());
        $this->assertTrue($expense->isExpense());
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @test */
    public function journal_entry_has_many_lines(): void
    {
        $period = AccountingPeriod::create(['period_name' => '2026-09', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'tenant_slug' => 'test-tenant']);
        $account = Account::create(['account_code' => 'ACC-REL', 'name' => 'Test', 'account_type' => 'ASSET', 'tenant_slug' => 'test-tenant']);
        $entry = JournalEntry::create(['reference' => 'JE-REL-001', 'posting_date' => '2026-09-01', 'period_id' => $period->id, 'tenant_slug' => 'test-tenant']);

        JournalLine::create(['entry_id' => $entry->id, 'account_id' => $account->id, 'debit' => 100, 'credit' => 0, 'tenant_slug' => 'test-tenant']);
        JournalLine::create(['entry_id' => $entry->id, 'account_id' => $account->id, 'debit' => 0, 'credit' => 100, 'tenant_slug' => 'test-tenant']);

        $this->assertCount(2, $entry->lines);
    }

    /** @test */
    public function journal_line_debit_credit_helpers_work(): void
    {
        $period = AccountingPeriod::create(['period_name' => '2026-08', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'tenant_slug' => 'test-tenant']);
        $account = Account::create(['account_code' => 'ACC-LINE', 'name' => 'Line Test', 'account_type' => 'ASSET', 'tenant_slug' => 'test-tenant']);
        $entry = JournalEntry::create(['reference' => 'JE-LINE-001', 'posting_date' => '2026-08-01', 'period_id' => $period->id, 'tenant_slug' => 'test-tenant']);

        $debitLine = JournalLine::create(['entry_id' => $entry->id, 'account_id' => $account->id, 'debit' => 500, 'credit' => 0, 'tenant_slug' => 'test-tenant']);
        $creditLine = JournalLine::create(['entry_id' => $entry->id, 'account_id' => $account->id, 'debit' => 0, 'credit' => 500, 'tenant_slug' => 'test-tenant']);

        $this->assertTrue($debitLine->isDebitLine());
        $this->assertFalse($debitLine->isCreditLine());
        $this->assertTrue($creditLine->isCreditLine());
        $this->assertFalse($creditLine->isDebitLine());
    }

    // -------------------------------------------------------------------------
    // Duplicate code rejection
    // -------------------------------------------------------------------------

    /** @test */
    public function duplicate_account_code_is_rejected(): void
    {
        Account::create(['account_code' => 'DUP-001', 'name' => 'First', 'account_type' => 'ASSET', 'tenant_slug' => 'test-tenant']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Account::create(['account_code' => 'DUP-001', 'name' => 'Second', 'account_type' => 'ASSET', 'tenant_slug' => 'test-tenant']);
    }

    /** @test */
    public function duplicate_period_name_is_rejected(): void
    {
        AccountingPeriod::create(['period_name' => 'DUP-PERIOD', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'tenant_slug' => 'test-tenant']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        AccountingPeriod::create(['period_name' => 'DUP-PERIOD', 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'tenant_slug' => 'test-tenant']);
    }

    // -------------------------------------------------------------------------
    // Domain Events
    // -------------------------------------------------------------------------

    /** @test */
    public function journal_entry_posted_event_can_be_instantiated(): void
    {
        $event = new JournalEntryPosted(
            entryId:    Str::uuid()->toString(),
            reference:  'JE-001',
            periodId:   Str::uuid()->toString(),
            totalDebit: 1000.00,
            tenantSlug: 'test-tenant',
        );
        $this->assertInstanceOf(JournalEntryPosted::class, $event);
        $this->assertEquals(1000.00, $event->totalDebit);
    }

    /** @test */
    public function period_closed_event_can_be_instantiated(): void
    {
        $event = new PeriodClosed(
            periodId:   Str::uuid()->toString(),
            periodName: '2026-10',
            tenantSlug: 'test-tenant',
        );
        $this->assertInstanceOf(PeriodClosed::class, $event);
        $this->assertSame('2026-10', $event->periodName);
    }
}
