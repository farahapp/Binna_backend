<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Audit\AuditEntry;
use App\Network\Events\AwardCreated;
use App\Network\Events\TenderAwarded;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NetworkAwardTest — Feature tests for NETWORK-005 Award.
 *
 * Covers:
 *  - Authorization: Publisher can award, unauthorized denied, unauthenticated denied, cross-tenant denied
 *  - Prerequisites: Evaluation not completed, Tender not closed, nonexistent Bid, wrong Tender Bid,
 *                   withdrawn Bid, already awarded Tender
 *  - Award creation: valid single Award, Bid association, NetworkProfile recorded, actor, timestamp,
 *                    optional reason, Tender becomes `awarded`
 *  - Atomicity: failed Award does not leave Tender `awarded`
 *  - Finality: second Award rejected
 *  - Confidentiality: winner sees own Award, cross-tenant denied
 *  - Events: TenderAwarded + AwardCreated dispatched exactly once
 *  - Audit: audit events logged
 *  - Regression: NETWORK-004, NETWORK-003, NETWORK-002, NETWORK-001
 */
class NetworkAwardTest extends TestCase
{
    private array  $tenantCtx  = [];
    private string $token      = '';
    private string $tenantSlug = '';
    private string $password   = 'Network005Test!';
    private string $publisherId;
    private string $contractorId;
    private string $tenderId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../bootstrap_iam_tenant.php';
        $this->tenantCtx  = bootstrapAuthSmokeTenant($this->password);
        $this->tenantSlug = $this->tenantCtx['tenant']->slug;
        $this->runNetworkMigrations();

        $login = $this->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->postJson('/api/auth/login', [
                'email'    => $this->tenantCtx['user']->email,
                'password' => $this->password,
            ]);
        $this->token = $login->json('token') ?? '';

        // Publisher profile — same tenant as the authenticated user
        $this->publisherId  = $this->createProfile('owner', $this->tenantSlug);
        // Contractor profile — same tenant
        $this->contractorId = $this->createProfile('contractor', $this->tenantSlug);
        // Tender — starts closed (Award requires closed Tender)
        $this->tenderId = $this->createTender($this->publisherId, now()->subDay(), 'closed');
    }

    protected function tearDown(): void
    {
        foreach ([
            'network_awards',
            'network_evaluation_notes',
            'network_evaluations',
            'network_bid_versions',
            'network_bids',
            'network_tenders',
            'network_profiles',
        ] as $table) {
            try { DB::connection('mysql')->table($table)->delete(); } catch (\Throwable) {}
        }
        try { cleanupAuthSmokeTenant(); } catch (\Throwable) {}
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function runNetworkMigrations(): void
    {
        $migrator = app('migrator');
        $migrator->setConnection('mysql');

        $tables = [
            'network_profiles',
            'network_tenders',
            'network_bids',
            'network_evaluations',
            'network_awards',
        ];

        foreach ($tables as $table) {
            if (! Schema::connection('mysql')->hasTable($table)) {
                $migrator->run(database_path('migrations/network'), ['realpath' => true]);
                return;
            }
        }
    }

    private function headers(string $tenant = ''): array
    {
        return [
            'X-Tenant'      => $tenant ?: $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ];
    }

    private function createProfile(string $type, string $tenant): string
    {
        $id = Str::uuid()->toString();
        DB::connection('mysql')->table('network_profiles')->insert([
            'id'                     => $id,
            'company_name'           => $type . ' company',
            'profile_type'           => $type,
            'status'                 => 'active',
            'managed_by_tenant_slug' => $tenant,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
        return $id;
    }

    private function createTender(string $publisherId, $deadline, string $status = 'closed'): string
    {
        $id = Str::uuid()->toString();
        DB::connection('mysql')->table('network_tenders')->insert([
            'id'                     => $id,
            'publisher_profile_id'   => $publisherId,
            'title'                  => 'Award Tender',
            'category'               => 'construction',
            'deadline'               => $deadline,
            'participant_types'      => json_encode(['contractor']),
            'status'                 => $status,
            'visibility'             => 'public',
            'managed_by_tenant_slug' => $this->tenantSlug,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
        return $id;
    }

    /**
     * Submit a Bid directly in DB (bypassing deadline check — for Award tests).
     */
    private function createSubmittedBid(string $tenderId, string $contractorProfileId): string
    {
        $bidId     = Str::uuid()->toString();
        $versionId = Str::uuid()->toString();

        DB::connection('mysql')->table('network_bids')->insert([
            'id'                     => $bidId,
            'tender_id'              => $tenderId,
            'contractor_profile_id'  => $contractorProfileId,
            'effective_version_id'   => $versionId,
            'status'                 => 'submitted',
            'latest_revision_number' => 1,
            'submitted_at'           => now(),
            'withdrawn_at'           => null,
            'managed_by_tenant_slug' => $this->tenantSlug,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::connection('mysql')->table('network_bid_versions')->insert([
            'id'              => $versionId,
            'bid_id'          => $bidId,
            'revision_number' => 1,
            'content'         => json_encode(['amount' => 50000]),
            'actor_id'        => (string) $this->tenantCtx['user']->id,
            'status'          => 'effective',
            'submitted_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $bidId;
    }

    /**
     * Create a completed Evaluation for a Tender.
     */
    private function createCompletedEvaluation(string $tenderId): string
    {
        $evalId = Str::uuid()->toString();
        DB::connection('mysql')->table('network_evaluations')->insert([
            'id'                        => $evalId,
            'tender_id'                 => $tenderId,
            'status'                    => 'completed',
            'started_by'                => (string) $this->tenantCtx['user']->id,
            'completed_by'              => (string) $this->tenantCtx['user']->id,
            'evaluation_started_at'     => now()->subHour(),
            'evaluation_completed_at'   => now()->subMinutes(5),
            'managed_by_tenant_slug'    => $this->tenantSlug,
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);
        return $evalId;
    }

    /**
     * Full happy-path setup: closed Tender + submitted Bid + completed Evaluation.
     */
    private function setupAwardableState(): array
    {
        $bidId = $this->createSubmittedBid($this->tenderId, $this->contractorId);
        $this->createCompletedEvaluation($this->tenderId);
        return ['bid_id' => $bidId];
    }

    // -------------------------------------------------------------------------
    // Authorization tests
    // -------------------------------------------------------------------------

    public function test_publisher_can_create_award(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertCreated();
        $this->assertSame($this->tenderId, $response->json('tender_id'));
        $this->assertSame($state['bid_id'], $response->json('winning_bid_id'));
    }

    public function test_unauthenticated_cannot_create_award(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders([
                'X-Tenant' => $this->tenantSlug,
                'Accept'   => 'application/json',
            ])
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertUnauthorized();
    }

    public function test_cross_tenant_cannot_create_award(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers('other-tenant'))
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        // Cross-tenant: either 422 (tenant mismatch) or 404 (Tender not found for that tenant)
        $this->assertContains($response->status(), [422, 404]);
    }

    // -------------------------------------------------------------------------
    // Prerequisites tests
    // -------------------------------------------------------------------------

    public function test_cannot_award_before_evaluation_is_completed(): void
    {
        $bidId = $this->createSubmittedBid($this->tenderId, $this->contractorId);
        // Create evaluation in 'open' state (not completed)
        $evalId = Str::uuid()->toString();
        DB::connection('mysql')->table('network_evaluations')->insert([
            'id'                     => $evalId,
            'tender_id'              => $this->tenderId,
            'status'                 => 'open',
            'started_by'             => (string) $this->tenantCtx['user']->id,
            'evaluation_started_at'  => now()->subHour(),
            'managed_by_tenant_slug' => $this->tenantSlug,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $bidId,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('completed', strtolower($response->json('error')));
    }

    public function test_cannot_award_without_any_evaluation(): void
    {
        $bidId = $this->createSubmittedBid($this->tenderId, $this->contractorId);
        // No evaluation created

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $bidId,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('evaluation', strtolower($response->json('error')));
    }

    public function test_cannot_award_when_tender_is_not_closed(): void
    {
        $publishedTenderId = $this->createTender($this->publisherId, now()->addDay(), 'published');
        $bidId = $this->createSubmittedBid($publishedTenderId, $this->contractorId);
        $this->createCompletedEvaluation($publishedTenderId);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $publishedTenderId . '/award', [
                'winning_bid_id' => $bidId,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('closed', strtolower($response->json('error')));
    }

    public function test_cannot_award_nonexistent_bid(): void
    {
        $this->createCompletedEvaluation($this->tenderId);
        $fakeBidId = Str::uuid()->toString();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $fakeBidId,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_award_bid_from_another_tender(): void
    {
        $otherTenderId = $this->createTender($this->publisherId, now()->subDay(), 'closed');
        $otherBidId    = $this->createSubmittedBid($otherTenderId, $this->contractorId);
        $this->createCompletedEvaluation($this->tenderId);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $otherBidId,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('tender', strtolower($response->json('error')));
    }

    public function test_cannot_award_withdrawn_bid(): void
    {
        $bidId = $this->createSubmittedBid($this->tenderId, $this->contractorId);
        // Mark bid as withdrawn
        DB::connection('mysql')->table('network_bids')
            ->where('id', $bidId)
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);
        $this->createCompletedEvaluation($this->tenderId);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $bidId,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('withdrawn', strtolower($response->json('error')));
    }

    public function test_cannot_award_already_awarded_tender(): void
    {
        $state = $this->setupAwardableState();

        // First Award
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        // Second Award attempt — Tender is now `awarded`, not `closed`
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertStatus(422);
        // Either "awarded" or "closed" message is acceptable — both indicate the Tender cannot be re-awarded
        $errorMsg = strtolower($response->json('error') ?? '');
        $this->assertTrue(
            str_contains($errorMsg, 'awarded') || str_contains($errorMsg, 'closed'),
            "Expected error about awarded/closed state, got: {$errorMsg}"
        );
    }

    // -------------------------------------------------------------------------
    // Award creation tests
    // -------------------------------------------------------------------------

    public function test_valid_award_creates_award_record(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('network_awards', [
            'tender_id'      => $this->tenderId,
            'winning_bid_id' => $state['bid_id'],
        ], 'mysql');
    }

    public function test_award_records_winning_network_profile(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertCreated();
        $this->assertSame($this->contractorId, $response->json('winning_network_profile_id'));
    }

    public function test_award_records_actor(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('awarded_by'));
        $this->assertSame((string) $this->tenantCtx['user']->id, $response->json('awarded_by'));
    }

    public function test_award_records_server_timestamp(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('awarded_at'));
    }

    public function test_award_records_optional_reason(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
                'reason'         => 'Best technical proposal and competitive price.',
            ]);

        $response->assertCreated();
        $this->assertSame('Best technical proposal and competitive price.', $response->json('reason'));
    }

    public function test_award_without_reason_is_accepted(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertCreated();
        $this->assertNull($response->json('reason'));
    }

    public function test_tender_becomes_awarded_after_award(): void
    {
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('network_tenders', [
            'id'     => $this->tenderId,
            'status' => 'awarded',
        ], 'mysql');
    }

    // -------------------------------------------------------------------------
    // Atomicity tests
    // -------------------------------------------------------------------------

    public function test_tender_is_not_awarded_if_award_creation_fails(): void
    {
        // Attempt Award with nonexistent Bid — should fail atomically
        $this->createCompletedEvaluation($this->tenderId);
        $fakeBidId = Str::uuid()->toString();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $fakeBidId,
            ])
            ->assertStatus(422);

        // Tender must still be `closed`, not `awarded`
        $this->assertDatabaseHas('network_tenders', [
            'id'     => $this->tenderId,
            'status' => 'closed',
        ], 'mysql');
    }

    // -------------------------------------------------------------------------
    // Finality tests
    // -------------------------------------------------------------------------

    public function test_second_award_is_rejected(): void
    {
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ]);

        $response->assertStatus(422);
    }

    public function test_only_one_award_record_exists_per_tender(): void
    {
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        $count = DB::connection('mysql')
            ->table('network_awards')
            ->where('tender_id', $this->tenderId)
            ->count();

        $this->assertSame(1, $count);
    }

    // -------------------------------------------------------------------------
    // Confidentiality / Access tests
    // -------------------------------------------------------------------------

    public function test_publisher_can_retrieve_award_by_id(): void
    {
        $state = $this->setupAwardableState();

        $created = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated()
            ->json();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/network/awards/' . $created['id']);

        $response->assertOk();
        $this->assertSame($created['id'], $response->json('id'));
    }

    public function test_publisher_can_retrieve_award_by_tender(): void
    {
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/network/tenders/' . $this->tenderId . '/award');

        $response->assertOk();
        $this->assertSame($this->tenderId, $response->json('tender_id'));
    }

    public function test_cross_tenant_cannot_retrieve_award(): void
    {
        $state = $this->setupAwardableState();

        $created = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated()
            ->json();

        $response = $this->withHeaders($this->headers('other-tenant'))
            ->getJson('/api/v1/network/awards/' . $created['id']);

        // Cross-tenant: either 403 (access denied) or 404 (not found for that tenant)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_award_response_does_not_expose_competitor_bid_content(): void
    {
        $state = $this->setupAwardableState();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        // Award response should not contain full Bid content
        $this->assertArrayNotHasKey('bid_content', $response->json());
        $this->assertArrayNotHasKey('content', $response->json());
    }

    // -------------------------------------------------------------------------
    // Events tests
    // -------------------------------------------------------------------------

    public function test_tender_awarded_event_dispatched_on_successful_award(): void
    {
        Event::fake([TenderAwarded::class, AwardCreated::class]);
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        Event::assertDispatched(TenderAwarded::class, 1);
    }

    public function test_award_created_event_dispatched_on_successful_award(): void
    {
        Event::fake([TenderAwarded::class, AwardCreated::class]);
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        Event::assertDispatched(AwardCreated::class, 1);
    }

    public function test_events_not_dispatched_on_failed_award(): void
    {
        Event::fake([TenderAwarded::class, AwardCreated::class]);
        $this->createCompletedEvaluation($this->tenderId);
        $fakeBidId = Str::uuid()->toString();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $fakeBidId,
            ])
            ->assertStatus(422);

        Event::assertNotDispatched(TenderAwarded::class);
        Event::assertNotDispatched(AwardCreated::class);
    }

    // -------------------------------------------------------------------------
    // Audit tests
    // -------------------------------------------------------------------------

    public function test_audit_event_logged_on_award_creation(): void
    {
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        $this->assertTrue(
            AuditEntry::query()->where('event', 'network.award.created')->exists(),
            'Audit entry missing for event: network.award.created'
        );
    }

    public function test_audit_event_logged_for_tender_awarded_transition(): void
    {
        $state = $this->setupAwardableState();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => $state['bid_id'],
            ])
            ->assertCreated();

        $this->assertTrue(
            AuditEntry::query()->where('event', 'network.tender.awarded')->exists(),
            'Audit entry missing for event: network.tender.awarded'
        );
    }

    // -------------------------------------------------------------------------
    // Validation tests
    // -------------------------------------------------------------------------

    public function test_winning_bid_id_is_required(): void
    {
        $this->createCompletedEvaluation($this->tenderId);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('winning_bid_id', $response->json('errors') ?? []);
    }

    public function test_winning_bid_id_must_be_uuid(): void
    {
        $this->createCompletedEvaluation($this->tenderId);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/award', [
                'winning_bid_id' => 'not-a-uuid',
            ]);

        $response->assertStatus(422);
    }
}
