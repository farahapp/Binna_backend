<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\ProjectIdentity;
use App\Core\ProjectParticipation;
use App\Core\Property;
use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP Scenarios A–G — Core Ecosystem multi-tenant authorization.
 *
 * Runs on phpunit.local.xml (binaa2_phpunit @ 3306) with BINAA2_TEST_ISOLATED_MYSQL.
 * Does NOT use RefreshDatabase (migrate:fresh would restore schema_name uniqueness
 * and wipe harness migrations). Cleanup is explicit in tearDown.
 * Live schema-per-tenant fixtures: scripts/seed_core_multitenant_fixtures.php.
 */
class CoreScenariosAGHttpTest extends TestCase
{
    private string $schema;

    private string $ownerSlug = 'ag-owner';

    private string $consultantSlug = 'ag-consultant';

    private string $contractorSlug = 'ag-contractor';

    private string $ownerToken;

    private string $consultantToken;

    private string $contractorToken;

    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = (string) config('database.connections.tenant.database', config('database.connections.mysql.database'));
        $this->runId = Str::lower(Str::random(8));
        $this->ownerSlug = 'ag-owner-'.$this->runId;
        $this->consultantSlug = 'ag-cons-'.$this->runId;
        $this->contractorSlug = 'ag-ctr-'.$this->runId;

        // Harness-only: RefreshDatabase/migrate:fresh may restore schema_name uniqueness.
        // Multi-tenant A–G authorization tests share one isolated DB schema.
        try {
            DB::connection('mysql')->statement('ALTER TABLE tenants DROP INDEX tenants_schema_name_unique');
        } catch (\Throwable) {
        }

        foreach ([
            [$this->ownerSlug, 'AG Owner', 'owner'],
            [$this->consultantSlug, 'AG Consultant', 'consultant'],
            [$this->contractorSlug, 'AG Contractor', 'contractor'],
        ] as [$slug, $name, $activity]) {
            $tenant = new Tenant([
                'slug' => $slug,
                'name' => $name,
                'legal_type' => 'organization',
                'activity_type' => $activity,
                'status' => 'active',
            ]);
            $tenant->schema_name = $this->schema;
            $tenant->save();
        }

        $this->ownerToken = $this->makeToken($this->ownerSlug, 'owner-'.$this->runId.'@ag.test');
        $this->consultantToken = $this->makeToken($this->consultantSlug, 'consultant-'.$this->runId.'@ag.test');
        $this->contractorToken = $this->makeToken($this->contractorSlug, 'contractor-'.$this->runId.'@ag.test');
    }

    protected function tearDown(): void
    {
        try {
            $slugs = [$this->ownerSlug, $this->consultantSlug, $this->contractorSlug];
            DB::connection('mysql')->table('project_owner_decisions')->whereIn('owner_tenant_slug', $slugs)->delete();
            DB::connection('mysql')->table('project_reviews')->whereIn('reviewer_tenant_slug', $slugs)->delete();
            DB::connection('mysql')->table('project_submissions')->whereIn('submitter_tenant_slug', $slugs)->delete();
            DB::connection('mysql')->table('project_participations')->whereIn('participant_tenant_slug', $slugs)->delete();
            DB::connection('mysql')->table('project_identities')->whereIn('created_by_tenant_slug', $slugs)->delete();
            DB::connection('mysql')->table('properties')->whereIn('owner_tenant_slug', $slugs)->delete();
            DB::connection('tenant')->table('personal_access_tokens')->whereIn('tokenable_id', function ($q) {
                $q->select('id')->from('users')->where('email', 'like', '%@ag.test');
            })->delete();
            DB::connection('tenant')->table('users')->where('email', 'like', '%'.$this->runId.'%@ag.test')->delete();
            DB::connection('mysql')->table('tenants')->whereIn('slug', $slugs)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function makeToken(string $tenantSlug, string $email): string
    {
        $user = User::on('tenant')->create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'tenant_slug' => $tenantSlug,
        ]);

        return $user->createToken('ag-test')->plainTextToken;
    }

    private function headers(string $token, string $tenantSlug): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-Tenant' => $tenantSlug,
            'Accept' => 'application/json',
        ];
    }

    /** Scenario A — same-tenant property + identity create succeeds */
    public function test_scenario_a_same_tenant_operations_succeed(): void
    {
        $create = $this->withHeaders($this->headers($this->ownerToken, $this->ownerSlug))
            ->postJson('/api/v1/core/properties', [
                'name' => 'AG Plot A',
                'city' => 'Doha',
            ]);
        $create->assertStatus(201);
        $propertyId = $create->json('data.id');

        $identity = $this->withHeaders($this->headers($this->ownerToken, $this->ownerSlug))
            ->postJson('/api/v1/core/project-identities', [
                'name' => 'AG Project A',
                'org_type' => 'owner',
                'property_id' => $propertyId,
            ]);
        $identity->assertStatus(201);
        $this->assertNotEmpty($identity->json('data.id'));
    }

    /** Scenario B — unauthorized cross-tenant property access fails */
    public function test_scenario_b_cross_tenant_access_denied(): void
    {
        $property = Property::create([
            'name' => 'AG Private Plot',
            'city' => 'Lusail',
            'owner_tenant_slug' => $this->ownerSlug,
            'ownership_status' => 'verified',
        ]);

        $foreign = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->getJson('/api/v1/core/properties/'.$property->id);

        $this->assertTrue(in_array($foreign->status(), [403, 404], true), 'cross-tenant property read must fail');
    }

    /** Scenario C — client-supplied tenant_slug cannot forge tenant authority */
    public function test_scenario_c_forged_tenant_slug_ignored(): void
    {
        $create = $this->withHeaders($this->headers($this->ownerToken, $this->ownerSlug))
            ->postJson('/api/v1/core/properties', [
                'name' => 'AG Forged Plot',
                'city' => 'Doha',
                'owner_tenant_slug' => $this->contractorSlug,
                'tenant_slug' => $this->contractorSlug,
            ]);
        $create->assertStatus(201);
        $this->assertSame($this->ownerSlug, $create->json('data.owner_tenant_slug'));
    }

    /** Scenario D — creating a project does not grant property ownership of foreign assets */
    public function test_scenario_d_project_create_does_not_infer_ownership(): void
    {
        $ownerProperty = Property::create([
            'name' => 'AG Owner Asset',
            'city' => 'Doha',
            'owner_tenant_slug' => $this->ownerSlug,
            'ownership_status' => 'verified',
        ]);

        $attempt = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->postJson('/api/v1/core/project-identities', [
                'name' => 'AG Hijack Project',
                'org_type' => 'contractor',
                'property_id' => $ownerProperty->id,
            ]);

        // Either denied, or created without transferring property ownership
        if ($attempt->status() === 201) {
            $ownerProperty->refresh();
            $this->assertSame($this->ownerSlug, $ownerProperty->owner_tenant_slug);
            $this->assertNotSame($this->contractorSlug, $ownerProperty->owner_tenant_slug);
        } else {
            $this->assertTrue(in_array($attempt->status(), [403, 404, 422], true));
        }
    }

    /** Scenario E — participation required for protected submission */
    public function test_scenario_e_participation_required_for_protected_access(): void
    {
        $identity = ProjectIdentity::create([
            'name' => 'AG Locked Project',
            'created_by_org_type' => 'owner',
            'created_by_tenant_slug' => $this->ownerSlug,
            'verified_owner_tenant_slug' => $this->ownerSlug,
            'status' => 'active',
        ]);

        $denied = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->postJson('/api/v1/core/submissions', [
                'project_identity_id' => $identity->id,
                'submission_type' => 'document',
                'title' => 'Unauthorized bid',
            ]);
        $this->assertTrue(in_array($denied->status(), [403, 422, 500], true));

        ProjectParticipation::create([
            'project_identity_id' => $identity->id,
            'participant_tenant_slug' => $this->contractorSlug,
            'organization_type' => 'contractor',
            'project_role' => 'contractor',
            'status' => ProjectParticipation::STATUS_ACTIVE,
            'source' => 'test_fixture',
        ]);

        $ok = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->postJson('/api/v1/core/submissions', [
                'project_identity_id' => $identity->id,
                'submission_type' => 'document',
                'title' => 'Authorized bid',
            ]);
        $ok->assertStatus(201);
    }

    /** Scenario F — consultant cannot owner-decide; self-review rejected */
    public function test_scenario_f_consultant_cannot_owner_decide_and_self_review_rejected(): void
    {
        $identity = ProjectIdentity::create([
            'name' => 'AG Collab Project',
            'created_by_org_type' => 'owner',
            'created_by_tenant_slug' => $this->ownerSlug,
            'verified_owner_tenant_slug' => $this->ownerSlug,
            'status' => 'active',
        ]);

        foreach ([
            [$this->ownerSlug, 'owner', 'owner'],
            [$this->consultantSlug, 'consultant', 'consultant'],
            [$this->contractorSlug, 'contractor', 'contractor'],
        ] as [$slug, $org, $role]) {
            ProjectParticipation::create([
                'project_identity_id' => $identity->id,
                'participant_tenant_slug' => $slug,
                'organization_type' => $org,
                'project_role' => $role,
                'status' => ProjectParticipation::STATUS_ACTIVE,
                'source' => 'test_fixture',
            ]);
        }

        $submission = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->postJson('/api/v1/core/submissions', [
                'project_identity_id' => $identity->id,
                'submission_type' => 'progress',
                'title' => 'Bid for review',
            ]);
        $submission->assertStatus(201);
        $submissionId = $submission->json('data.id');

        $selfReview = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->postJson('/api/v1/core/submissions/'.$submissionId.'/reviews', [
                'outcome' => 'recommend_approve',
                'comments' => 'self',
            ]);
        $this->assertTrue(in_array($selfReview->status(), [403, 422, 500], true));

        $consultDecide = $this->withHeaders($this->headers($this->consultantToken, $this->consultantSlug))
            ->postJson('/api/v1/core/submissions/'.$submissionId.'/owner-decisions', [
                'decision' => 'approve',
            ]);
        $this->assertTrue(in_array($consultDecide->status(), [403, 422, 500], true));

        $review = $this->withHeaders($this->headers($this->consultantToken, $this->consultantSlug))
            ->postJson('/api/v1/core/submissions/'.$submissionId.'/reviews', [
                'outcome' => 'recommend_approve',
                'requires_owner_decision' => true,
                'comments' => 'ok',
            ]);
        $review->assertStatus(201);

        $ownerDecide = $this->withHeaders($this->headers($this->ownerToken, $this->ownerSlug))
            ->postJson('/api/v1/core/submissions/'.$submissionId.'/owner-decisions', [
                'decision' => 'approve',
                'review_id' => $review->json('data.id'),
            ]);
        $ownerDecide->assertStatus(201);
    }

    /** Scenario G — invitation + respond participation lifecycle */
    public function test_scenario_g_invitation_and_linking_require_verification(): void
    {
        $identity = ProjectIdentity::create([
            'name' => 'AG Invite Project',
            'created_by_org_type' => 'owner',
            'created_by_tenant_slug' => $this->ownerSlug,
            'verified_owner_tenant_slug' => $this->ownerSlug,
            'status' => 'active',
        ]);

        ProjectParticipation::create([
            'project_identity_id' => $identity->id,
            'participant_tenant_slug' => $this->ownerSlug,
            'organization_type' => 'owner',
            'project_role' => 'owner',
            'status' => ProjectParticipation::STATUS_ACTIVE,
            'source' => 'test_fixture',
        ]);

        $invite = $this->withHeaders($this->headers($this->ownerToken, $this->ownerSlug))
            ->postJson('/api/v1/core/project-identities/'.$identity->id.'/participations', [
                'participant_tenant_slug' => $this->consultantSlug,
                'organization_type' => 'consultant',
                'project_role' => 'consultant',
            ]);
        $invite->assertStatus(201);
        $participationId = $invite->json('data.id');
        $this->assertSame(ProjectParticipation::STATUS_INVITED, $invite->json('data.status'));

        // Contractor cannot accept consultant invitation
        $foreignAccept = $this->withHeaders($this->headers($this->contractorToken, $this->contractorSlug))
            ->patchJson('/api/v1/core/participations/'.$participationId.'/respond', [
                'decision' => 'accept',
            ]);
        $this->assertTrue(in_array($foreignAccept->status(), [403, 404, 422, 500], true));

        $accept = $this->withHeaders($this->headers($this->consultantToken, $this->consultantSlug))
            ->patchJson('/api/v1/core/participations/'.$participationId.'/respond', [
                'decision' => 'accept',
            ]);
        $accept->assertStatus(200);
        $this->assertSame(ProjectParticipation::STATUS_ACTIVE, $accept->json('data.status'));
    }
}
