<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Accounting\AccountingService;
use App\Accounting\Events\JournalEntryPosted;
use App\Accounting\Events\PeriodClosed;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AccountingHttpApiTest — Feature tests for Accounting HTTP API Layer.
 * TASK-014C — Accounting HTTP API Layer
 * CAP-120/121
 */
class AccountingHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'accounting-http-test';
    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        [$this->user, $this->token] = $this->createUserAndToken();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('posting_rules')->delete();
            DB::connection('tenant')->table('journal_lines')->delete();
            DB::connection('tenant')->table('journal_entries')->delete();
            DB::connection('tenant')->table('accounting_periods')->delete();
            DB::connection('tenant')->table('accounts')->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
        parent::tearDown();
    }

    private function setUpTenant(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Accounting HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'        => 'Accounting Test User',
            'email'       => 'acctest@example.com',
            'password'    => Hash::make('password'),
            'tenant_slug' => $this->tenantSlug,
        ]);
        $token = $user->createToken('accounting-test-token')->plainTextToken;
        return [$user, $token];
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ];
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenantSlug, 'Accept' => 'application/json'];
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    /** @test */
    public function returns_401_without_token_for_accounts(): void
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/v1/accounting/accounts', []);
        $response->assertStatus(401);
    }

    /** @test */
    public function returns_401_without_token_for_journal_entries(): void
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/v1/accounting/journal-entries', []);
        $response->assertStatus(401);
    }

    // =========================================================================
    // CAP-120 — Chart of Accounts
    // =========================================================================

    /** @test */
    public function create_account_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/accounting/accounts', [
                'tenant_slug'  => $this->tenantSlug,
                'account_code' => '1000',
                'name'         => 'Cash',
                'account_type' => 'ASSET',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['account_code' => '1000']);
        $response->assertJsonFragment(['account_type' => 'ASSET']);
        $response->assertJsonFragment(['status' => 'active']);
    }

    /** @test */
    public function create_account_returns_422_for_invalid_type(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/accounting/accounts', [
                'tenant_slug'  => $this->tenantSlug,
                'account_code' => '9999',
                'name'         => 'Invalid',
                'account_type' => 'INVALID',
            ]);
        $response->assertStatus(422);
    }

    /** @test */
    public function get_account_balance_returns_200(): void
    {
        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $account = $service->createAccount($this->tenantSlug, '1001', 'AR', 'ASSET');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/accounting/accounts/{$account->id}/balance");

        $response->assertStatus(200);
        $response->assertJsonFragment(['account_id' => $account->id]);
        $response->assertJsonStructure(['account_id', 'account_code', 'account_type', 'total_debit', 'total_credit', 'balance']);
    }

    // =========================================================================
    // CAP-120 — Accounting Periods
    // =========================================================================

    /** @test */
    public function create_period_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/accounting/periods', [
                'tenant_slug' => $this->tenantSlug,
                'period_name' => '2026-10',
                'start_date'  => '2026-10-01',
                'end_date'    => '2026-10-31',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['period_name' => '2026-10']);
        $response->assertJsonFragment(['status' => 'OPEN']);
    }

    /** @test */
    public function close_period_returns_200_with_closed_status(): void
    {
        Event::fake([PeriodClosed::class]);

        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $period = $service->createPeriod($this->tenantSlug, '2026-09', '2026-09-01', '2026-09-30');

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson("/api/v1/accounting/periods/{$period->id}/close", [
                'tenant_slug' => $this->tenantSlug,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'CLOSED']);
    }

    /** @test */
    public function is_period_open_returns_correct_value(): void
    {
        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $period = $service->createPeriod($this->tenantSlug, '2026-08', '2026-08-01', '2026-08-31');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/accounting/periods/{$period->id}/is-open");

        $response->assertStatus(200);
        $response->assertJsonFragment(['is_open' => true]);
    }

    // =========================================================================
    // CAP-121 — Journal Entries
    // =========================================================================

    /** @test */
    public function create_journal_entry_returns_201_with_lines(): void
    {
        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $debitAcc = $service->createAccount($this->tenantSlug, '1100', 'AR HTTP', 'ASSET');
        $creditAcc = $service->createAccount($this->tenantSlug, '4000', 'Revenue HTTP', 'REVENUE');
        $period = $service->createPeriod($this->tenantSlug, '2026-10-HTTP', '2026-10-01', '2026-10-31');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/accounting/journal-entries', [
                'tenant_slug'  => $this->tenantSlug,
                'reference'    => 'JE-HTTP-001',
                'posting_date' => '2026-10-03',
                'period_id'    => $period->id,
                'narration'    => 'Test entry',
                'lines' => [
                    ['account_id' => $debitAcc->id, 'debit' => 1000.0, 'credit' => 0.0],
                    ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 1000.0],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['reference' => 'JE-HTTP-001']);
        $response->assertJsonFragment(['status' => 'DRAFT']);
        $response->assertJsonStructure(['id', 'reference', 'status', 'lines']);
    }

    /** @test */
    public function post_journal_entry_returns_200_with_posted_status(): void
    {
        Event::fake([JournalEntryPosted::class]);

        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $debitAcc = $service->createAccount($this->tenantSlug, '1200', 'AR Post', 'ASSET');
        $creditAcc = $service->createAccount($this->tenantSlug, '4100', 'Rev Post', 'REVENUE');
        $period = $service->createPeriod($this->tenantSlug, '2026-10-POST', '2026-10-01', '2026-10-31');
        $entry = $service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-HTTP-POST-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 500.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 500.0],
            ],
        );

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson("/api/v1/accounting/journal-entries/{$entry->id}/post", [
                'tenant_slug' => $this->tenantSlug,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'POSTED']);
    }

    /** @test */
    public function get_journal_entry_returns_entry_with_lines(): void
    {
        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $debitAcc = $service->createAccount($this->tenantSlug, '1300', 'AR Get', 'ASSET');
        $creditAcc = $service->createAccount($this->tenantSlug, '4200', 'Rev Get', 'REVENUE');
        $period = $service->createPeriod($this->tenantSlug, '2026-10-GET', '2026-10-01', '2026-10-31');
        $entry = $service->createJournalEntry(
            tenantSlug:  $this->tenantSlug,
            reference:   'JE-HTTP-GET-001',
            postingDate: '2026-10-03',
            periodId:    $period->id,
            lines: [
                ['account_id' => $debitAcc->id, 'debit' => 300.0, 'credit' => 0.0],
                ['account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => 300.0],
            ],
        );

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/accounting/journal-entries/{$entry->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['reference' => 'JE-HTTP-GET-001']);
        $response->assertJsonStructure(['id', 'reference', 'status', 'lines']);
    }

    /** @test */
    public function get_trial_balance_returns_200(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/accounting/trial-balance?tenant_slug=' . $this->tenantSlug);

        $response->assertStatus(200);
        $response->assertJsonStructure(['tenant_slug', 'rows', 'total_debit', 'total_credit', 'is_balanced']);
    }

    // =========================================================================
    // CAP-121 — Posting Rules
    // =========================================================================

    /** @test */
    public function create_posting_rule_returns_201(): void
    {
        /** @var AccountingService $service */
        $service = app(AccountingService::class);
        $debitAcc = $service->createAccount($this->tenantSlug, '1400', 'AR Rule', 'ASSET');
        $creditAcc = $service->createAccount($this->tenantSlug, '4300', 'Rev Rule', 'REVENUE');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/accounting/posting-rules', [
                'tenant_slug'       => $this->tenantSlug,
                'trigger_event'     => 'InvoiceIssued',
                'rule_name'         => 'Invoice Revenue Recognition',
                'debit_account_id'  => $debitAcc->id,
                'credit_account_id' => $creditAcc->id,
                'amount_source'     => 'totalAmount',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['trigger_event' => 'InvoiceIssued']);
        $response->assertJsonFragment(['is_active' => true]);
    }
}
