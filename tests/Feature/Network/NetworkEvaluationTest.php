<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Audit\AuditEntry;
use App\Network\Events\EvaluationCompleted;
use App\Network\Events\EvaluationStarted;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NetworkEvaluationTest — Feature tests for NETWORK-004 Bid Evaluation.
 *
 * Covers:
 *  - Lifecycle: not_started → open → completed
 *  - Access control (publisher tenant only)
 *  - Evaluation Notes (internal, open evaluation only)
 *  - Bid access during evaluation
 *  - Bid revision/withdrawal blocked after tender closed (NETWORK-003 regression)
 *  - Domain events: EvaluationStarted, EvaluationCompleted
 *  - Audit logging
 */
class NetworkEvaluationTest extends TestCase
{
    private array  $tenantCtx  = [];
    private string $token      = '';
    private string $tenantSlug = '';
    private string $password   = 'Network004Test!';
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
        // Contractor profile — SAME tenant as the authenticated user (BidService.assertProfileOwnership requires this)
        $this->contractorId = $this->createProfile('contractor', $this->tenantSlug);
        // Tender — managed by publisher tenant, starts published
        $this->tenderId = $this->createTender($this->publisherId, now()->addDay(), 'published');
    }

    protected function tearDown(): void
    {
        foreach ([
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

        if (! Schema::connection('mysql')->hasTable('network_profiles')) {
            $migrator->run(database_path('migrations/network'), ['realpath' => true]);
            return;
        }
        if (! Schema::connection('mysql')->hasTable('network_tenders')) {
            $migrator->run(database_path('migrations/network'), ['realpath' => true]);
            return;
        }
        if (! Schema::connection('mysql')->hasTable('network_bids')) {
            $migrator->run(database_path('migrations/network'), ['realpath' => true]);
            return;
        }
        if (! Schema::connection('mysql')->hasTable('network_evaluations')) {
            $migrator->run(database_path('migrations/network'), ['realpath' => true]);
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

    private function createTender(string $publisherId, $deadline, string $status = 'published'): string
    {
        $id = Str::uuid()->toString();
        DB::connection('mysql')->table('network_tenders')->insert([
            'id'                     => $id,
            'publisher_profile_id'   => $publisherId,
            'title'                  => 'Evaluation Tender',
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

    private function closeTender(string $tenderId): void
    {
        DB::connection('mysql')->table('network_tenders')
            ->where('id', $tenderId)
            ->update(['status' => 'closed', 'updated_at' => now()]);
    }

    private function startEvaluation(string $tenderId = ''): array
    {
        $tid = $tenderId ?: $this->tenderId;
        $this->closeTender($tid);
        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $tid . '/evaluation')
            ->assertCreated()
            ->json();
    }

    private function submitBid(string $contractorProfileId = '', array $content = ['amount' => 5000]): array
    {
        $profileId = $contractorProfileId ?: $this->contractorId;
        // Bids are submitted from the contractor's tenant context — but we use the publisher token
        // (same pattern as NetworkBidTest: the test user submits on behalf of the contractor profile)
        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/bids', [
                'tender_id'              => $this->tenderId,
                'contractor_profile_id'  => $profileId,
                'content'                => $content,
            ])
            ->assertCreated()
            ->json('data');
    }

    // -------------------------------------------------------------------------
    // Lifecycle tests
    // -------------------------------------------------------------------------

    public function test_publisher_can_start_evaluation_on_closed_tender(): void
    {
        $this->closeTender($this->tenderId);

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/evaluation');

        $response->assertCreated();
        $this->assertSame('open', $response->json('status'));
        $this->assertNotNull($response->json('evaluation_started_at'));
        $this->assertDatabaseHas('network_evaluations', [
            'tender_id' => $this->tenderId,
            'status'    => 'open',
        ], 'mysql');
    }

    public function test_cannot_start_evaluation_on_published_tender(): void
    {
        // Tender is still published (not closed)
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/evaluation');

        $response->assertStatus(422);
        $this->assertStringContainsString('closed', strtolower($response->json('error')));
    }

    public function test_cannot_start_evaluation_on_draft_tender(): void
    {
        $draftTenderId = $this->createTender($this->publisherId, now()->addDay(), 'draft');

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $draftTenderId . '/evaluation');

        $response->assertStatus(422);
    }

    public function test_cannot_start_evaluation_twice(): void
    {
        $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/evaluation');

        $response->assertStatus(422);
        $this->assertStringContainsString('already exists', strtolower($response->json('error')));
    }

    public function test_publisher_can_complete_evaluation(): void
    {
        $evaluation = $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/complete');

        $response->assertOk();
        $this->assertSame('completed', $response->json('status'));
        $this->assertNotNull($response->json('evaluation_completed_at'));
    }

    public function test_cannot_complete_not_started_evaluation(): void
    {
        // Create evaluation record directly in not_started state
        $evalId = Str::uuid()->toString();
        DB::connection('mysql')->table('network_evaluations')->insert([
            'id'                     => $evalId,
            'tender_id'              => $this->tenderId,
            'status'                 => 'not_started',
            'managed_by_tenant_slug' => $this->tenantSlug,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $response = $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evalId . '/complete');

        $response->assertStatus(422);
    }

    public function test_cannot_reopen_completed_evaluation(): void
    {
        $evaluation = $this->startEvaluation();
        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/complete')
            ->assertOk();

        // Try to complete again — should fail (already completed, not open)
        $response = $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/complete');

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Access tests
    // -------------------------------------------------------------------------

    public function test_publisher_can_access_evaluation(): void
    {
        $evaluation = $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/network/evaluations/' . $evaluation['id']);

        $response->assertOk();
        $this->assertSame($evaluation['id'], $response->json('id'));
        $this->assertArrayHasKey('notes', $response->json());
    }

    public function test_publisher_can_access_bids_after_evaluation_starts(): void
    {
        // Submit a bid before closing
        $this->submitBid();
        $evaluation = $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/bids');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_cannot_access_bids_before_evaluation_starts(): void
    {
        // Create evaluation in not_started state directly
        $evalId = Str::uuid()->toString();
        DB::connection('mysql')->table('network_evaluations')->insert([
            'id'                     => $evalId,
            'tender_id'              => $this->tenderId,
            'status'                 => 'not_started',
            'managed_by_tenant_slug' => $this->tenantSlug,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/network/evaluations/' . $evalId . '/bids');

        $this->assertContains($response->status(), [403, 422]);
    }

    public function test_contractor_cannot_access_evaluation_of_another_tenant(): void
    {
        $evaluation = $this->startEvaluation();

        // Access with a different tenant slug
        $response = $this->withHeaders($this->headers('other-tenant'))
            ->getJson('/api/v1/network/evaluations/' . $evaluation['id']);

        $this->assertContains($response->status(), [401, 403, 404]);
    }

    // -------------------------------------------------------------------------
    // Notes tests
    // -------------------------------------------------------------------------

    public function test_publisher_can_add_note_during_open_evaluation(): void
    {
        $evaluation = $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/notes', [
                'content' => 'This bid looks promising.',
            ]);

        $response->assertCreated();
        $this->assertSame('This bid looks promising.', $response->json('content'));
    }

    public function test_cannot_add_note_to_completed_evaluation(): void
    {
        $evaluation = $this->startEvaluation();
        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/complete')
            ->assertOk();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/notes', [
                'content' => 'Too late.',
            ]);

        $response->assertStatus(422);
    }

    public function test_note_is_returned_with_evaluation(): void
    {
        $evaluation = $this->startEvaluation();
        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/notes', [
                'content' => 'Internal note.',
            ])
            ->assertCreated();

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/network/evaluations/' . $evaluation['id']);

        $response->assertOk();
        $this->assertCount(1, $response->json('notes'));
        $this->assertSame('Internal note.', $response->json('notes.0.content'));
    }

    public function test_note_can_reference_a_bid_id(): void
    {
        $bid        = $this->submitBid();
        $evaluation = $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/notes', [
                'content' => 'Note about this specific bid.',
                'bid_id'  => $bid['id'],
            ]);

        $response->assertCreated();
        $this->assertSame($bid['id'], $response->json('bid_id'));
    }

    // -------------------------------------------------------------------------
    // Bid integrity tests (NETWORK-003 regression)
    // -------------------------------------------------------------------------

    public function test_bid_revision_blocked_after_evaluation_starts(): void
    {
        // Submit bid while tender is published
        $bid = $this->submitBid();

        // Close tender and start evaluation
        $this->startEvaluation();

        // Try to revise — tender is now closed, so BidService.assertSubmissionWindow() blocks it
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/bids/' . $bid['id'] . '/revisions', [
                'content' => ['amount' => 9999],
            ]);

        $response->assertStatus(422);
    }

    public function test_bid_withdrawal_blocked_after_evaluation_starts(): void
    {
        $bid = $this->submitBid();
        $this->startEvaluation();

        $response = $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/bids/' . $bid['id'] . '/withdraw');

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Events tests
    // -------------------------------------------------------------------------

    public function test_evaluation_started_event_dispatched(): void
    {
        Event::fake([EvaluationStarted::class]);
        $this->closeTender($this->tenderId);

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/tenders/' . $this->tenderId . '/evaluation')
            ->assertCreated();

        Event::assertDispatched(EvaluationStarted::class, function (EvaluationStarted $event) {
            return $event->evaluation->tender_id === $this->tenderId;
        });
    }

    public function test_evaluation_completed_event_dispatched(): void
    {
        Event::fake([EvaluationCompleted::class]);
        $evaluation = $this->startEvaluation();

        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/complete')
            ->assertOk();

        Event::assertDispatched(EvaluationCompleted::class, function (EvaluationCompleted $event) use ($evaluation) {
            return $event->evaluation->id === $evaluation['id'];
        });
    }

    // -------------------------------------------------------------------------
    // Audit test
    // -------------------------------------------------------------------------

    public function test_evaluation_actions_are_audited(): void
    {
        $evaluation = $this->startEvaluation();

        $this->withHeaders($this->headers())
            ->postJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/notes', [
                'content' => 'Audit test note.',
            ])
            ->assertCreated();

        $this->withHeaders($this->headers())
            ->patchJson('/api/v1/network/evaluations/' . $evaluation['id'] . '/complete')
            ->assertOk();

        foreach (['network.evaluation.started', 'network.evaluation.note.added', 'network.evaluation.completed'] as $event) {
            $this->assertTrue(
                AuditEntry::query()->where('event', $event)->exists(),
                "Audit entry missing for event: {$event}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Schema isolation test
    // -------------------------------------------------------------------------

    public function test_evaluation_tables_are_in_global_mysql_schema(): void
    {
        $this->assertTrue(
            Schema::connection('mysql')->hasTable('network_evaluations'),
            'network_evaluations must exist in global (mysql) schema.'
        );
        $this->assertTrue(
            Schema::connection('mysql')->hasTable('network_evaluation_notes'),
            'network_evaluation_notes must exist in global (mysql) schema.'
        );
        $this->assertFalse(
            Schema::connection('tenant')->hasTable('network_evaluations'),
            'network_evaluations must NOT exist in tenant schema.'
        );
    }
}
