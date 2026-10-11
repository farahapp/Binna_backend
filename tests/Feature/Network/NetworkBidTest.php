<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Audit\AuditEntry;
use App\Network\Events\BidRevised;
use App\Network\Events\BidRevisionRejected;
use App\Network\Events\BidSubmitted;
use App\Network\Events\BidWithdrawn;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class NetworkBidTest extends TestCase
{
    private array $tenantCtx = [];
    private string $token = '';
    private string $tenantSlug = '';
    private string $password = 'Network003Test!';
    private string $contractorId;
    private string $tenderId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        $this->tenantCtx = bootstrapAuthSmokeTenant($this->password);
        $this->tenantSlug = $this->tenantCtx['tenant']->slug;
        $this->runNetworkMigrations();
        $login = $this->withHeaders(['X-Tenant' => $this->tenantSlug])->postJson('/api/auth/login', [
            'email' => $this->tenantCtx['user']->email, 'password' => $this->password,
        ]);
        $this->token = $login->json('token') ?? '';
        $this->contractorId = $this->createProfile('contractor', $this->tenantSlug);
        $publisher = $this->createProfile('owner', 'publisher-tenant');
        $this->tenderId = $this->createTender($publisher, now()->addDay());
    }

    protected function tearDown(): void
    {
        foreach (['network_bid_versions', 'network_bids', 'network_tenders', 'network_profiles'] as $table) {
            try { DB::connection('mysql')->table($table)->delete(); } catch (\Throwable) {}
        }
        try { cleanupAuthSmokeTenant(); } catch (\Throwable) {}
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runNetworkMigrations(): void
    {
        $migrator = app('migrator');
        $migrator->setConnection('mysql');
        foreach (glob(database_path('migrations/network/*.php')) as $file) {
            $table = str_contains($file, 'profiles') ? 'network_profiles' : (str_contains($file, 'tenders') ? 'network_tenders' : 'network_bids');
            if (! Schema::connection('mysql')->hasTable($table)) {
                $migrator->run(dirname($file), ['realpath' => true]);
                break;
            }
        }
        if (! Schema::connection('mysql')->hasTable('network_bids')) {
            $migrator->run(database_path('migrations/network'), ['realpath' => true]);
        }
    }

    private function headers(): array
    {
        return ['X-Tenant' => $this->tenantSlug, 'Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function createProfile(string $type, string $tenant): string
    {
        $id = Str::uuid()->toString();
        DB::connection('mysql')->table('network_profiles')->insert([
            'id' => $id, 'company_name' => $type.' company', 'profile_type' => $type,
            'status' => 'active', 'managed_by_tenant_slug' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function createTender(string $publisher, $deadline, string $status = 'published'): string
    {
        $id = Str::uuid()->toString();
        DB::connection('mysql')->table('network_tenders')->insert([
            'id' => $id, 'publisher_profile_id' => $publisher, 'title' => 'Bid Tender', 'category' => 'construction',
            'deadline' => $deadline, 'participant_types' => json_encode(['contractor']), 'status' => $status,
            'visibility' => 'public', 'managed_by_tenant_slug' => 'publisher-tenant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function submitBid(array $content = ['amount' => 1000]): array
    {
        return $this->withHeaders($this->headers())->postJson('/api/v1/network/bids', [
            'tender_id' => $this->tenderId, 'contractor_profile_id' => $this->contractorId, 'content' => $content,
        ])->assertCreated()->json('data');
    }

    public function test_eligible_contractor_can_submit_and_bid_is_global(): void
    {
        $bid = $this->submitBid();
        $this->assertSame('submitted', $bid['status']);
        $this->assertDatabaseHas('network_bids', ['id' => $bid['id'], 'contractor_profile_id' => $this->contractorId], 'mysql');
    }

    public function test_unauthenticated_submission_is_rejected(): void
    {
        $this->withHeaders(['X-Tenant' => $this->tenantSlug])->postJson('/api/v1/network/bids', [])->assertUnauthorized();
    }

    public function test_ineligible_contractor_and_invalid_tender_state_are_rejected(): void
    {
        $supplier = $this->createProfile('supplier', $this->tenantSlug);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids', [
            'tender_id' => $this->tenderId, 'contractor_profile_id' => $supplier, 'content' => ['amount' => 1],
        ])->assertStatus(422);
        DB::connection('mysql')->table('network_tenders')->where('id', $this->tenderId)->update(['status' => 'closed']);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids', [
            'tender_id' => $this->tenderId, 'contractor_profile_id' => $this->contractorId, 'content' => ['amount' => 1],
        ])->assertStatus(422);
    }

    public function test_before_deadline_accepted_exact_and_after_rejected(): void
    {
        Carbon::setTestNow('2026-10-08 10:00:00');
        DB::connection('mysql')->table('network_tenders')->where('id', $this->tenderId)->update(['deadline' => '2026-10-08 10:00:01']);
        $this->submitBid();
        DB::connection('mysql')->table('network_bids')->delete();
        DB::connection('mysql')->table('network_bid_versions')->delete();
        DB::connection('mysql')->table('network_tenders')->where('id', $this->tenderId)->update(['deadline' => '2026-10-08 10:00:00']);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids', [
            'tender_id' => $this->tenderId, 'contractor_profile_id' => $this->contractorId, 'content' => ['amount' => 1],
        ])->assertStatus(422);
        DB::connection('mysql')->table('network_tenders')->where('id', $this->tenderId)->update(['deadline' => '2026-10-08 09:59:59']);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids', [
            'tender_id' => $this->tenderId, 'contractor_profile_id' => $this->contractorId, 'content' => ['amount' => 1],
        ])->assertStatus(422);
    }

    public function test_revision_is_immutable_and_event_emitted(): void
    {
        Event::fake([BidRevised::class]);
        $bid = $this->submitBid(['amount' => 1000]);
        $response = $this->withHeaders($this->headers())->postJson('/api/v1/network/bids/'.$bid['id'].'/revisions', [
            'content' => ['amount' => 1200], 'reason' => 'Updated price',
        ])->assertOk();
        $this->assertCount(2, $response->json('data.versions'));
        $this->assertSame(1000, $response->json('data.versions.0.content.amount'));
        $this->assertSame('superseded', $response->json('data.versions.0.status'));
        Event::assertDispatched(BidRevised::class);
    }

    public function test_revision_after_deadline_is_rejected_audited_and_event_emitted(): void
    {
        $bid = $this->submitBid();
        DB::connection('mysql')->table('network_tenders')->where('id', $this->tenderId)->update(['deadline' => now()->subSecond()]);
        Event::fake([BidRevisionRejected::class]);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids/'.$bid['id'].'/revisions', [
            'content' => ['amount' => 2],
        ])->assertStatus(422);
        Event::assertDispatched(BidRevisionRejected::class);
        $this->assertTrue(AuditEntry::query()->where('event', 'network.bid.revision_rejected')->exists());
    }

    public function test_withdrawal_preserves_history_and_event_emitted(): void
    {
        Event::fake([BidWithdrawn::class]);
        $bid = $this->submitBid();
        $response = $this->withHeaders($this->headers())->patchJson('/api/v1/network/bids/'.$bid['id'].'/withdraw', [
            'reason' => 'No longer participating',
        ])->assertOk();
        $this->assertSame('withdrawn', $response->json('data.status'));
        $this->assertCount(1, $response->json('data.versions'));
        Event::assertDispatched(BidWithdrawn::class);
    }

    public function test_unauthorized_contractor_cannot_view_revise_or_withdraw(): void
    {
        $bid = $this->submitBid();
        DB::connection('mysql')->table('network_bids')->where('id', $bid['id'])->update(['managed_by_tenant_slug' => 'other-tenant']);
        $this->withHeaders($this->headers())->getJson('/api/v1/network/bids/'.$bid['id'])->assertStatus(403);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids/'.$bid['id'].'/revisions', ['content' => ['amount' => 2]])->assertStatus(422);
        $this->withHeaders($this->headers())->patchJson('/api/v1/network/bids/'.$bid['id'].'/withdraw')->assertStatus(422);
    }

    public function test_submission_revision_and_withdrawal_are_audited_and_submission_event_emitted(): void
    {
        Event::fake([BidSubmitted::class]);
        $bid = $this->submitBid();
        Event::assertDispatched(BidSubmitted::class);
        $this->withHeaders($this->headers())->postJson('/api/v1/network/bids/'.$bid['id'].'/revisions', ['content' => ['amount' => 2]])->assertOk();
        $this->withHeaders($this->headers())->patchJson('/api/v1/network/bids/'.$bid['id'].'/withdraw')->assertOk();
        foreach (['network.bid.submitted', 'network.bid.revised', 'network.bid.withdrawn'] as $event) {
            $this->assertTrue(AuditEntry::query()->where('event', $event)->exists(), $event);
        }
    }
}
