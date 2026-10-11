<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Finance\FinanceService;
use App\Finance\Invoice;
use App\Finance\Receivable;
use App\Finance\Events\InvoiceIssued;
use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FinanceHttpApiTest — Feature tests for Finance HTTP API Layer.
 * TASK-013C — Finance HTTP API Layer
 * CAP-110/111/180/181
 */
class FinanceHttpApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantSlug = 'finance-http-test';
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
            DB::connection('tenant')->table('collections')->delete();
            DB::connection('tenant')->table('receivables')->delete();
            DB::connection('tenant')->table('invoice_items')->delete();
            DB::connection('tenant')->table('invoices')->delete();
            DB::connection('tenant')->table('payments')->delete();
            DB::connection('tenant')->table('bank_reconciliations')->delete();
            DB::connection('tenant')->table('bank_transactions')->delete();
            DB::connection('tenant')->table('bank_accounts')->delete();
            DB::connection('tenant')->table('cash_transactions')->delete();
            DB::connection('tenant')->table('cash_accounts')->delete();
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
            'name'        => 'Finance HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'        => 'Finance Test User',
            'email'       => 'fintest@example.com',
            'password'    => Hash::make('password'),
            'tenant_slug' => $this->tenantSlug,
        ]);
        $token = $user->createToken('finance-test-token')->plainTextToken;
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
        return [
            'X-Tenant' => $this->tenantSlug,
            'Accept'   => 'application/json',
        ];
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    /** @test */
    public function returns_401_without_token_for_cash_accounts(): void
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/v1/finance/cash-accounts', []);
        $response->assertStatus(401);
    }

    /** @test */
    public function returns_401_without_token_for_invoices(): void
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/v1/finance/invoices', []);
        $response->assertStatus(401);
    }

    // =========================================================================
    // CAP-110 — Cash Management
    // =========================================================================

    /** @test */
    public function create_cash_account_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/cash-accounts', [
                'tenant_slug'  => $this->tenantSlug,
                'account_code' => 'CASH-HTTP-001',
                'name'         => 'HTTP Petty Cash',
                'currency'     => 'QAR',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['account_code' => 'CASH-HTTP-001']);
        $response->assertJsonFragment(['status' => 'active']);
    }

    /** @test */
    public function create_cash_account_returns_422_for_missing_fields(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/cash-accounts', [
                'tenant_slug' => $this->tenantSlug,
                // missing account_code and name
            ]);
        $response->assertStatus(422);
    }

    /** @test */
    public function record_cash_transaction_returns_201(): void
    {
        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $account = $service->createCashAccount($this->tenantSlug, 'CASH-TX-HTTP', 'TX Test');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/cash-transactions', [
                'tenant_slug'      => $this->tenantSlug,
                'cash_account_id'  => $account->id,
                'type'             => 'IN',
                'amount'           => 250.00,
                'transaction_date' => '2026-10-03',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['type' => 'IN']);
    }

    // =========================================================================
    // CAP-111 — Bank Management
    // =========================================================================

    /** @test */
    public function create_bank_account_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/bank-accounts', [
                'tenant_slug'    => $this->tenantSlug,
                'account_code'   => 'BANK-HTTP-001',
                'bank_name'      => 'QNB',
                'account_number' => '9876543210',
                'currency'       => 'QAR',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['account_code' => 'BANK-HTTP-001']);
        $response->assertJsonFragment(['bank_name' => 'QNB']);
    }

    /** @test */
    public function record_bank_transaction_returns_201(): void
    {
        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $account = $service->createBankAccount($this->tenantSlug, 'BANK-TX-HTTP', 'TX Bank', '111222');
        Event::fake();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/bank-transactions', [
                'tenant_slug'      => $this->tenantSlug,
                'bank_account_id'  => $account->id,
                'type'             => 'CREDIT',
                'amount'           => 10000.00,
                'transaction_date' => '2026-10-03',
                'reference'        => 'REF-001',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['type' => 'CREDIT']);
    }

    /** @test */
    public function get_bank_balance_returns_correct_balance(): void
    {
        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $account = $service->createBankAccount($this->tenantSlug, 'BANK-BAL-HTTP', 'Balance Bank', '333444');
        Event::fake();
        $service->recordBankTransaction($this->tenantSlug, $account->id, 'CREDIT', 7500.00, '2026-10-03');

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/finance/bank-accounts/{$account->id}/balance");

        $response->assertStatus(200);
        $response->assertJsonFragment(['balance' => 7500.0]);
    }

    // =========================================================================
    // CAP-180 — Client Billing & Invoicing
    // =========================================================================

    /** @test */
    public function create_invoice_returns_201_with_draft_status(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/invoices', [
                'tenant_slug'    => $this->tenantSlug,
                'invoice_number' => 'INV-HTTP-001',
                'client_id'      => Str::uuid()->toString(),
                'invoice_date'   => '2026-10-03',
                'due_date'       => '2026-11-03',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['invoice_number' => 'INV-HTTP-001']);
        $response->assertJsonFragment(['status' => 'DRAFT']);
    }

    /** @test */
    public function add_invoice_item_returns_201(): void
    {
        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $invoice = $service->createInvoice($this->tenantSlug, 'INV-ITEM-HTTP', Str::uuid()->toString(), '2026-10-03');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/finance/invoices/{$invoice->id}/items", [
                'tenant_slug' => $this->tenantSlug,
                'description' => 'Construction Services',
                'quantity'    => 5.0,
                'unit_price'  => 200.0,
                'tax_rate'    => 0.0,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['description' => 'Construction Services']);
    }

    /** @test */
    public function issue_invoice_returns_200_with_issued_status(): void
    {
        Event::fake([InvoiceIssued::class]);

        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $invoice = $service->createInvoice($this->tenantSlug, 'INV-ISSUE-HTTP', Str::uuid()->toString(), '2026-10-03');
        $service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item', 1.0, 1000.0);

        $response = $this->withHeaders($this->authHeaders())
            ->patchJson("/api/v1/finance/invoices/{$invoice->id}/issue", [
                'tenant_slug' => $this->tenantSlug,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'ISSUED']);
    }

    /** @test */
    public function get_invoice_returns_invoice_with_items(): void
    {
        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $invoice = $service->createInvoice($this->tenantSlug, 'INV-GET-HTTP', Str::uuid()->toString(), '2026-10-03');
        $service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Service', 2.0, 300.0);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/finance/invoices/{$invoice->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['invoice_number' => 'INV-GET-HTTP']);
        $response->assertJsonStructure(['id', 'invoice_number', 'status', 'items']);
    }

    // =========================================================================
    // CAP-181 — Receivables & Collections
    // =========================================================================

    /** @test */
    public function record_collection_returns_201(): void
    {
        // Fake all Finance events — this is an HTTP API test, not an integration test.
        // Accounting listener behavior is tested in FinanceAccountingIntegrationTest.
        Event::fake();

        /** @var FinanceService $service */
        $service = app(FinanceService::class);
        $invoice = $service->createInvoice($this->tenantSlug, 'INV-COL-HTTP', Str::uuid()->toString(), '2026-10-03');
        $service->addInvoiceItem($this->tenantSlug, $invoice->id, 'Item', 1.0, 800.0);
        $service->issueInvoice($this->tenantSlug, $invoice->id);

        $receivable = Receivable::where('invoice_id', $invoice->id)->first();

        // TASK-016 §3.1/§3.2: bank_account_id required for BANK_TRANSFER (DEC-006)
        $bankAccount = $service->createBankAccount($this->tenantSlug, 'BANK-COL-HTTP', 'Collection Bank', '999888');

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/collections', [
                'tenant_slug'     => $this->tenantSlug,
                'receivable_id'   => $receivable->id,
                'amount'          => 400.00,
                'collection_date' => '2026-10-03',
                'payment_method'  => 'BANK_TRANSFER',
                'bank_account_id' => $bankAccount->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['amount' => '400.0000']);
    }

    /** @test */
    public function record_payment_returns_201(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/payments', [
                'tenant_slug'    => $this->tenantSlug,
                'payment_number' => 'PAY-HTTP-001',
                'payment_type'   => 'SUPPLIER',
                'payment_method' => 'BANK_TRANSFER',
                'party_id'       => Str::uuid()->toString(),
                'party_type'     => 'supplier',
                'amount'         => 3000.00,
                'payment_date'   => '2026-10-03',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['payment_number' => 'PAY-HTTP-001']);
        $response->assertJsonFragment(['payment_type' => 'SUPPLIER']);
    }

    /** @test */
    public function record_payment_returns_422_for_invalid_payment_type(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/finance/payments', [
                'tenant_slug'    => $this->tenantSlug,
                'payment_number' => 'PAY-INVALID',
                'payment_type'   => 'INVALID_TYPE',
                'payment_method' => 'CASH',
                'party_id'       => Str::uuid()->toString(),
                'party_type'     => 'supplier',
                'amount'         => 100.00,
                'payment_date'   => '2026-10-03',
            ]);

        $response->assertStatus(422);
    }
}
