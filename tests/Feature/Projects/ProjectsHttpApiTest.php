<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\IAM\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ProjectsHttpApiTest — Feature tests for TASK-005C Projects HTTP API Layer.
 *
 * Tests:
 *  - Authentication boundary — all endpoints return 401 without token
 *  - POST /api/v1/projects                              (201)
 *  - GET  /api/v1/projects/{id}                         (200 + 404)
 *  - GET  /api/v1/projects/{id}/status                  (200)
 *  - GET  /api/v1/projects/{id}/team                    (200)
 *  - GET  /api/v1/projects/{id}/can-close               (200)
 *  - PATCH /api/v1/projects/{id}/activate               (200)
 *  - PATCH /api/v1/projects/{id}/progress               (200)
 *  - POST /api/v1/projects/{id}/activities              (201)
 *  - POST /api/v1/projects/{id}/daily-reports           (201)
 *  - POST /api/v1/projects/{id}/milestones              (201)
 *  - POST /api/v1/projects/{id}/team                    (201)
 *  - PATCH /api/v1/projects/{id}/close                  (200)
 *  - PATCH /api/v1/projects/milestones/{id}/reached     (200)
 *
 * TASK-005C — Projects HTTP API Layer
 */
class ProjectsHttpApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CORE-01C-3D: Restrict transaction wrapping to the 'mysql' connection only.
     * The 'tenant' connection is configured manually in setUpTenant() and cleaned
     * up explicitly in tearDown(), so wrapping it in a transaction here causes
     * migrate:fresh / schema conflicts. Both connections point to binaa2_testing
     * on port 3307 in the test environment.
     */
    protected array $connectionsToTransact = ['mysql'];

    private string $tenantSlug = 'projects-http-test';
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
            // CORE-01C-2: clean project_parties before projects
            DB::connection('tenant')->table('project_parties')->delete();
            DB::connection('tenant')->table('project_team')->delete();
            DB::connection('tenant')->table('project_milestones')->delete();
            DB::connection('tenant')->table('daily_reports')->delete();
            DB::connection('tenant')->table('project_activities')->delete();
            DB::connection('tenant')->table('projects')->delete();
            DB::connection('tenant')->table('clients')->where('tenant_slug', $this->tenantSlug)->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
        // CORE-01C-3E: Purge the tenant connection before parent::tearDown() so
        // that no open PDO handle to binaa2_testing remains when RefreshDatabase
        // resets $migrated and migrate:fresh drops/recreates tables for the next
        // test. Without this, MySQL may refuse to drop 'users' (already exists)
        // because the tenant PDO still holds a reference to the same schema.
        DB::purge('tenant');
        parent::tearDown();
    }

    // =========================================================================
    // SETUP HELPERS
    // =========================================================================

    private function setUpTenant(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Projects HTTP Test Tenant',
            'schema_name' => 'binaa2_testing',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Config::set('database.connections.tenant.driver', 'mysql');
        Config::set('database.connections.tenant.host', config('database.connections.mysql.host', '127.0.0.1'));
        Config::set('database.connections.tenant.port', config('database.connections.mysql.port', '3307'));
        Config::set('database.connections.tenant.database', 'binaa2_testing');
        Config::set('database.connections.tenant.username', config('database.connections.mysql.username'));
        Config::set('database.connections.tenant.password', config('database.connections.mysql.password'));
        Config::set('database.connections.tenant.charset', 'utf8mb4');
        Config::set('database.connections.tenant.collation', 'utf8mb4_unicode_ci');
        Config::set('database.connections.tenant.prefix', '');
        Config::set('database.connections.tenant.strict', true);
        DB::purge('tenant');
    }

    private function createUserAndToken(): array
    {
        $user = User::on('tenant')->create([
            'name'     => 'Projects Test User',
            'email'    => 'projectstest@example.com',
            'password' => Hash::make('password'),
        ]);
        $token = $user->createToken('test-token')->plainTextToken;
        return [$user, $token];
    }

    private function authHeaders(): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenantSlug];
    }

    /**
     * Helper: create a project via the API and return its ID.
     */
    private function createProjectViaApi(array $overrides = []): string
    {
        $payload = array_merge([
            'name' => 'Test Project',
        ], $overrides);

        $response = $this->postJson('/api/v1/projects', $payload, $this->authHeaders());
        $response->assertStatus(201);
        return $response->json('id');
    }

    // =========================================================================
    // UNAUTHENTICATED — 401
    // =========================================================================

    public function test_unauthenticated_post_project_returns_401(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Unauthorized Project',
        ], $this->tenantHeaders());

        $response->assertStatus(401);
    }

    public function test_unauthenticated_get_project_returns_401(): void
    {
        $response = $this->getJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000',
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    public function test_unauthenticated_get_project_status_returns_401(): void
    {
        $response = $this->getJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/status',
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // POST /api/v1/projects — Create Project
    // =========================================================================

    public function test_create_project_returns_201(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'New Construction Project',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'New Construction Project']);
        $this->assertNotNull($response->json('id'));
    }

    public function test_create_project_with_all_fields_returns_201(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'name'        => 'Full Project',
            'description' => 'A detailed project description',
            'start_date'  => '2025-01-01',
            'end_date'    => '2025-12-31',
        ], $this->authHeaders());

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Full Project']);
    }

    public function test_create_project_missing_name_returns_422(): void
    {
        $response = $this->postJson('/api/v1/projects', [
            'description' => 'No name provided',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/projects/{id} — Get Project
    // =========================================================================

    public function test_get_project_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000',
            $this->authHeaders()
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Project not found.']);
    }

    public function test_get_project_found_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Retrievable Project']);

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $projectId]);
    }

    // =========================================================================
    // GET /api/v1/projects/{id}/status — Get Project Status
    // =========================================================================

    public function test_get_project_status_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/status',
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_get_project_status_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Status Project']);

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/status",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    // =========================================================================
    // GET /api/v1/projects/{id}/team — Get Project Team
    // =========================================================================

    public function test_get_project_team_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Team Project']);

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/team",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    // =========================================================================
    // GET /api/v1/projects/{id}/can-close — Can Close Project
    // =========================================================================

    public function test_can_close_project_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Closeable Project']);

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/can-close",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    // =========================================================================
    // PATCH /api/v1/projects/{id}/activate — Activate Project
    // =========================================================================

    public function test_activate_project_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/activate',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_activate_project_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Project To Activate']);

        $response = $this->patchJson(
            "/api/v1/projects/{$projectId}/activate",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'active']);
    }

    // =========================================================================
    // PATCH /api/v1/projects/{id}/progress — Update Progress
    // =========================================================================

    public function test_update_progress_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Progress Project']);

        // Activate first
        $this->patchJson("/api/v1/projects/{$projectId}/activate", [], $this->authHeaders())
            ->assertStatus(200);

        $response = $this->patchJson(
            "/api/v1/projects/{$projectId}/progress",
            ['progress_percentage' => 50],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['progress_percentage' => 50]);
    }

    public function test_update_progress_missing_field_returns_422(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Progress Validation Project']);

        $response = $this->patchJson(
            "/api/v1/projects/{$projectId}/progress",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /api/v1/projects/{id}/activities — Add Activity
    // =========================================================================

    public function test_add_activity_returns_201(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Activity Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Foundation Work'],
            $this->authHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Foundation Work']);
        $response->assertJsonFragment(['project_id' => $projectId]);
    }

    public function test_add_activity_missing_name_returns_422(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Activity Validation Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['description' => 'No name'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /api/v1/projects/{id}/daily-reports — Submit Daily Report
    // =========================================================================

    public function test_submit_daily_report_returns_201(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Daily Report Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/daily-reports",
            [
                'report_date'  => '2025-06-15',
                'submitted_by' => '00000000-0000-0000-0000-000000000099',
            ],
            $this->authHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['project_id' => $projectId]);
    }

    public function test_submit_daily_report_missing_required_fields_returns_422(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Daily Report Validation Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/daily-reports",
            ['weather' => 'Sunny'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // GET /api/v1/projects/{id}/daily-reports — List Daily Reports (M2.3A-FIX)
    // =========================================================================

    public function test_list_daily_reports_returns_200_with_submitted_report(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'List Reports Project']);

        // Submit a report first
        $this->postJson(
            "/api/v1/projects/{$projectId}/daily-reports",
            [
                'report_date'         => '2026-10-14',
                'submitted_by'        => '00000000-0000-0000-0000-000000000099',
                'weather'             => 'Sunny',
                'work_done'           => 'Foundation work',
                'progress_percentage' => 40,
            ],
            $this->authHeaders()
        )->assertStatus(201);

        // List reports
        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/daily-reports",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['id', 'project_id', 'report_date', 'submitted_by', 'tenant_slug']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonFragment(['project_id' => $projectId]);
        $response->assertJsonFragment(['submitted_by' => '00000000-0000-0000-0000-000000000099']);
    }

    public function test_list_daily_reports_does_not_return_reports_from_other_project(): void
    {
        // Project A — has a report
        $projectAId = $this->createProjectViaApi(['name' => 'Project A — Has Reports']);
        $this->postJson(
            "/api/v1/projects/{$projectAId}/daily-reports",
            [
                'report_date'  => '2026-10-14',
                'submitted_by' => '00000000-0000-0000-0000-000000000099',
            ],
            $this->authHeaders()
        )->assertStatus(201);

        // Project B — no reports
        $projectBId = $this->createProjectViaApi(['name' => 'Project B — No Reports']);

        $response = $this->getJson(
            "/api/v1/projects/{$projectBId}/daily-reports",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 0);
        $response->assertJsonCount(0, 'data');
    }

    public function test_list_daily_reports_returns_empty_data_for_project_with_no_reports(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Empty Reports Project']);

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/daily-reports",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_list_daily_reports_returns_404_for_nonexistent_project(): void
    {
        $response = $this->getJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/daily-reports',
            $this->authHeaders()
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Project not found.']);
    }

    // =========================================================================
    // POST /api/v1/projects/{id}/milestones — Add Milestone
    // =========================================================================

    public function test_add_milestone_returns_201(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Milestone Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/milestones",
            [
                'name'     => 'Phase 1 Complete',
                'due_date' => '2025-09-30',
            ],
            $this->authHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Phase 1 Complete']);
        $response->assertJsonFragment(['project_id' => $projectId]);
    }

    public function test_add_milestone_missing_name_returns_422(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Milestone Validation Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/milestones",
            ['due_date' => '2025-09-30'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /api/v1/projects/{id}/team — Assign Team Member
    // =========================================================================

    public function test_assign_team_member_returns_201(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Team Assignment Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/team",
            [
                'user_id' => '00000000-0000-0000-0000-000000000088',
                'role'    => 'engineer',
            ],
            $this->authHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['project_id' => $projectId]);
        $response->assertJsonFragment(['user_id' => '00000000-0000-0000-0000-000000000088']);
    }

    public function test_assign_team_member_missing_user_id_returns_422(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Team Validation Project']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/team",
            ['role' => 'engineer'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // =========================================================================
    // PATCH /api/v1/projects/{id}/close — Close Project
    // =========================================================================

    public function test_close_project_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/close',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_close_project_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Project To Close']);

        // Activate first (draft → active → closed)
        $this->patchJson("/api/v1/projects/{$projectId}/activate", [], $this->authHeaders())
            ->assertStatus(200);

        $response = $this->patchJson(
            "/api/v1/projects/{$projectId}/close",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'closed']);
    }

    // =========================================================================
    // PATCH /api/v1/projects/milestones/{id}/reached — Mark Milestone Reached
    // =========================================================================

    public function test_mark_milestone_reached_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/projects/milestones/00000000-0000-0000-0000-000000000000/reached',
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    public function test_mark_milestone_reached_returns_200(): void
    {
        $projectId = $this->createProjectViaApi(['name' => 'Milestone Reached Project']);

        // Add a milestone
        $milestoneResponse = $this->postJson(
            "/api/v1/projects/{$projectId}/milestones",
            ['name' => 'Milestone To Reach'],
            $this->authHeaders()
        );
        $milestoneResponse->assertStatus(201);
        $milestoneId = $milestoneResponse->json('id');

        $response = $this->patchJson(
            "/api/v1/projects/milestones/{$milestoneId}/reached",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'reached']);
    }

    // =========================================================================
    // PATCH /api/v1/projects/activities/{id}/status — Activity Status Update
    // API-GAP-M2-01 — WBS activity lifecycle: pending → in_progress → completed / cancelled
    // =========================================================================

    public function test_unauthenticated_patch_activity_status_returns_401(): void
    {
        $response = $this->patchJson(
            '/api/v1/projects/activities/00000000-0000-0000-0000-000000000000/status',
            ['status' => 'in_progress'],
            $this->tenantHeaders()
        );
        $response->assertStatus(401);
    }

    public function test_update_activity_status_pending_to_in_progress_returns_200(): void
    {
        $projectId  = $this->createProjectViaApi(['name' => 'Activity Status — In Progress']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Excavation Work'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'in_progress'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $activityId]);
        $response->assertJsonFragment(['status' => 'in_progress']);
    }

    public function test_update_activity_status_in_progress_to_completed_returns_200(): void
    {
        $projectId  = $this->createProjectViaApi(['name' => 'Activity Status — Completed']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Concrete Pouring'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        // pending → in_progress
        $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'in_progress'],
            $this->authHeaders()
        )->assertStatus(200);

        // in_progress → completed
        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'completed'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'completed']);
    }

    public function test_update_activity_status_pending_to_cancelled_returns_200(): void
    {
        $projectId  = $this->createProjectViaApi(['name' => 'Activity Status — Cancelled']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Cancelled Scope'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'cancelled'],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_update_activity_status_invalid_value_returns_422(): void
    {
        $projectId  = $this->createProjectViaApi(['name' => 'Activity Status — Invalid Value']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Test Activity'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'not-a-real-status'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_update_activity_status_invalid_transition_returns_422(): void
    {
        // pending → completed is not a valid transition (must go via in_progress)
        $projectId  = $this->createProjectViaApi(['name' => 'Activity Status — Invalid Transition']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Transition Test'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'completed'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_update_activity_status_not_found_returns_404(): void
    {
        $response = $this->patchJson(
            '/api/v1/projects/activities/00000000-0000-0000-0000-000000000000/status',
            ['status' => 'in_progress'],
            $this->authHeaders()
        );

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Activity not found.']);
    }

    public function test_update_activity_status_from_terminal_state_returns_422(): void
    {
        // cancelled is a terminal state — no further transitions allowed
        $projectId  = $this->createProjectViaApi(['name' => 'Activity Status — Terminal']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Terminal Activity'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        // Move to cancelled (terminal)
        $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'cancelled'],
            $this->authHeaders()
        )->assertStatus(200);

        // Any further transition must fail
        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'pending'],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_update_activity_status_cross_tenant_isolation_returns_404(): void
    {
        // F-06 — Cross-tenant isolation for PATCH /activities/{id}/status.
        //
        // Setup: create an activity under the primary test tenant (Tenant A).
        // Then issue the PATCH request using a second tenant's X-Tenant header
        // (Tenant B) but with the same authenticated user token.
        // The service must not find the activity because tenant_slug does not
        // match, and must return 404 — no information leakage about Tenant A's
        // record existence.
        //
        // The activity record is real and exists in the DB; this test proves
        // isolation is enforced by the compound (id + tenant_slug) predicate,
        // not by a missing UUID.

        // ── 1. Create a real activity under Tenant A (the primary test tenant) ──
        $projectId  = $this->createProjectViaApi(['name' => 'Tenant A Project — Isolation']);
        $activityId = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Tenant A Activity'],
            $this->authHeaders()
        )->assertStatus(201)->json('id');

        // Confirm the activity exists and is in pending state under Tenant A.
        $this->assertDatabaseHas('project_activities', [
            'id'          => $activityId,
            'tenant_slug' => $this->tenantSlug,
            'status'      => 'pending',
        ], 'tenant');

        // -- 2. Register Tenant B via a mock TenantContextResolver --
        //
        // Root-cause fix (SESSION 25D):
        // The previous fixture used schema_name = 'binaa2_testing' for Tenant B,
        // which duplicates the primary tenant's schema_name. The unique constraint
        // on tenants.schema_name caused insertOrIgnore to silently skip the row,
        // leaving Tenant B unregistered. The middleware then returned
        // "Tenant not found." before the request reached the service, making the
        // test pass for the wrong reason (middleware 404, not isolation 404).
        //
        // Fix: mock the TenantContextResolver singleton so that Tenant B's slug
        // resolves to a TenantContext with schemaName = 'binaa2_testing' (the
        // same physical schema used in the test environment). This avoids the
        // unique constraint entirely and ensures SwitchToTenantSchema can issue
        // USE inaa2_testing without an Access Denied error.
        //
        // The isolation predicate under test is tenant_slug in project_activities,
        // not the schema boundary. Both tenants legitimately share binaa2_testing
        // in the test environment.
        $tenantBSlug = 'projects-http-test-tenant-b';

        // Bind a mock resolver that returns a synthetic TenantContext for Tenant B
        // and delegates all other slugs to the real resolver.
        $realResolver = $this->app->make(\App\Foundation\TenantContextResolver::class);
        $this->app->instance(
            \App\Foundation\TenantContextResolver::class,
            new class($realResolver, $tenantBSlug) extends \App\Foundation\TenantContextResolver {
                public function __construct(
                    private readonly \App\Foundation\TenantContextResolver $real,
                    private readonly string $tenantBSlug,
                ) {}
                public function tryResolve(string $slug): ?\App\Foundation\TenantContext {
                    if ($slug === $this->tenantBSlug) {
                        return new \App\Foundation\TenantContext(
                            id: 99999,
                            slug: $this->tenantBSlug,
                            name: 'Tenant B -- Isolation Test',
                            schemaName: 'binaa2_testing',
                            status: 'active',
                        );
                    }
                    return $this->real->tryResolve($slug);
                }
                public function resolve(string $slug): \App\Foundation\TenantContext {
                    if ($slug === $this->tenantBSlug) {
                        return $this->tryResolve($slug);
                    }
                    return $this->real->resolve($slug);
                }
            }
        );

        // Explicit setup assertion: the mock must resolve Tenant B before we proceed.
        $resolvedB = $this->app->make(\App\Foundation\TenantContextResolver::class)->tryResolve($tenantBSlug);
        $this->assertNotNull($resolvedB, 'Fixture failure: Tenant B mock did not resolve.');
        $this->assertSame($tenantBSlug, $resolvedB->slug);

        try {
            // -- 3. Issue PATCH with Tenant B's X-Tenant header --
            // The activity belongs to Tenant A; the service must return 404
            // because the compound predicate (id + tenant_slug) does not match
            // Tenant B's slug -- not because the schema is different.
            $response = $this->patchJson(
                "/api/v1/projects/activities/{$activityId}/status",
                ['status' => 'in_progress'],
                [
                    'X-Tenant'      => $tenantBSlug,
                    'Authorization' => 'Bearer ' . $this->token,
                ]
            );

            $response->assertStatus(404);
            $response->assertJsonFragment(['message' => 'Activity not found.']);

            // -- 4. Verify the activity was NOT mutated --
            $this->assertDatabaseHas('project_activities', [
                'id'     => $activityId,
                'status' => 'pending',
            ], 'tenant');

            // -- 4b. Verify Tenant A can still update its own activity --
            $this->patchJson(
                "/api/v1/projects/activities/{$activityId}/status",
                ['status' => 'in_progress'],
                $this->authHeaders()
            )->assertStatus(200);

            $this->assertDatabaseHas('project_activities', [
                'id'     => $activityId,
                'status' => 'in_progress',
            ], 'tenant');
        } finally {
            // -- 5. Restore the real TenantContextResolver singleton --
            $this->app->instance(\App\Foundation\TenantContextResolver::class, $realResolver);
        }
    }

    // =========================================================================
    // PARTY OPERATIONS (CORE-01C-2)
    // NOTE: The tests below require the project_parties table (Migration
    //       2026_09_28_000007) to be applied to binaa2_testing.
    //       If that migration has NOT been run, these tests will fail with
    //       a "Table ... doesn't exist" error — not a logic failure.
    // =========================================================================

    /**
     * Helper: insert an active client directly into the tenant DB and return its UUID.
     */
    private function createActiveClient(?string $name = null): string
    {
        $id = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('tenant')->table('clients')->insert([
            'id'          => $id,
            'type'        => 'company',
            'name'        => $name ?? 'Test Party Client',
            'status'      => 'active',
            'tenant_slug' => $this->tenantSlug,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return $id;
    }

    /**
     * Helper: insert an inactive client and return its UUID.
     */
    private function createInactiveClient(): string
    {
        $id = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('tenant')->table('clients')->insert([
            'id'          => $id,
            'type'        => 'company',
            'name'        => 'Inactive Party Client',
            'status'      => 'inactive',
            'tenant_slug' => $this->tenantSlug,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return $id;
    }

    // ── Auth boundary ─────────────────────────────────────────────────────────

    /** @test */
    public function test_unauthenticated_get_parties_returns_401(): void
    {
        // CORE-01C-4: use a fixed UUID (no prior authenticated request in this
        // test) so the Sanctum guard has no session/token to fall back on.
        // Pattern matches other 401 boundary tests (e.g. test_unauthenticated_get_project_returns_401).
        $response = $this->getJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/parties',
            $this->tenantHeaders()   // no auth token
        );

        $response->assertStatus(401);
    }

    /** @test */
    public function test_unauthenticated_put_parties_returns_401(): void
    {
        // CORE-01C-4: use a fixed UUID — no prior authenticated request.
        $response = $this->putJson(
            '/api/v1/projects/00000000-0000-0000-0000-000000000000/parties',
            ['parties' => []],
            $this->tenantHeaders()   // no auth token
        );

        $response->assertStatus(401);
    }

    // ── GET /api/v1/projects/{id}/parties ─────────────────────────────────────

    /** @test */
    public function test_list_parties_returns_empty_array_for_new_project(): void
    {
        $projectId = $this->createProjectViaApi();

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/parties",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    /** @test */
    public function test_list_parties_returns_saved_parties(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createActiveClient();

        $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId, 'role' => 'owner']]],
            $this->authHeaders()
        )->assertStatus(200);

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}/parties",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $response->assertJsonFragment(['client_id' => $clientId, 'role' => 'owner']);
    }

    // ── PUT /api/v1/projects/{id}/parties ─────────────────────────────────────

    /** @test */
    public function test_replace_parties_returns_200_with_empty_list(): void
    {
        $projectId = $this->createProjectViaApi();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => []],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    /** @test */
    public function test_replace_parties_saves_single_party(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createActiveClient();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId, 'role' => 'owner']]],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $response->assertJsonFragment(['client_id' => $clientId, 'role' => 'owner']);
    }

    /** @test */
    public function test_replace_parties_allows_multiple_roles_for_same_client(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createActiveClient();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [
                ['client_id' => $clientId, 'role' => 'owner'],
                ['client_id' => $clientId, 'role' => 'consultant'],
            ]],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    /** @test */
    public function test_replace_parties_allows_multiple_clients_for_same_role(): void
    {
        $projectId  = $this->createProjectViaApi();
        $clientId1  = $this->createActiveClient('Client A');
        $clientId2  = $this->createActiveClient('Client B');

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [
                ['client_id' => $clientId1, 'role' => 'consultant'],
                ['client_id' => $clientId2, 'role' => 'consultant'],
            ]],
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    /** @test */
    public function test_replace_parties_is_idempotent_replacement(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId1 = $this->createActiveClient('Client Old');
        $clientId2 = $this->createActiveClient('Client New');

        // First PUT
        $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId1, 'role' => 'owner']]],
            $this->authHeaders()
        )->assertStatus(200);

        // Second PUT replaces completely
        $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId2, 'role' => 'consultant']]],
            $this->authHeaders()
        )->assertStatus(200);

        $list = $this->getJson(
            "/api/v1/projects/{$projectId}/parties",
            $this->authHeaders()
        )->json();

        $this->assertCount(1, $list);
        $this->assertEquals($clientId2, $list[0]['client_id']);
        $this->assertEquals('consultant', $list[0]['role']);
    }

    // ── Validation errors ─────────────────────────────────────────────────────

    /** @test */
    public function test_replace_parties_rejects_invalid_role_returns_422(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createActiveClient();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId, 'role' => 'janitor']]],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_replace_parties_rejects_duplicate_pair_returns_422(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createActiveClient();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [
                ['client_id' => $clientId, 'role' => 'owner'],
                ['client_id' => $clientId, 'role' => 'owner'],
            ]],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_replace_parties_rejects_nonexistent_client_returns_422(): void
    {
        $projectId = $this->createProjectViaApi();
        $fakeId    = '00000000-0000-0000-0000-000000000000';

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $fakeId, 'role' => 'owner']]],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_replace_parties_rejects_inactive_client_returns_422(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createInactiveClient();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId, 'role' => 'owner']]],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_replace_parties_rejects_missing_parties_field_returns_422(): void
    {
        $projectId = $this->createProjectViaApi();

        $response = $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    // ── GET /api/v1/projects/{id} includes parties ────────────────────────────

    /** @test */
    public function test_get_project_includes_parties_key(): void
    {
        $projectId = $this->createProjectViaApi();

        $response = $this->getJson(
            "/api/v1/projects/{$projectId}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $this->assertArrayHasKey('parties', $response->json());
    }

    /** @test */
    public function test_get_project_parties_reflects_replaced_parties(): void
    {
        $projectId = $this->createProjectViaApi();
        $clientId  = $this->createActiveClient();

        $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $clientId, 'role' => 'main_contractor']]],
            $this->authHeaders()
        )->assertStatus(200);

        $project = $this->getJson(
            "/api/v1/projects/{$projectId}",
            $this->authHeaders()
        )->json();

        $this->assertCount(1, $project['parties']);
        $this->assertEquals($clientId, $project['parties'][0]['client_id']);
    }

    // ── Atomicity / Rollback ──────────────────────────────────────────────────

    /** @test */
    public function test_replace_parties_rollback_on_invalid_client_keeps_original(): void
    {
        $projectId      = $this->createProjectViaApi();
        $validClientId  = $this->createActiveClient();
        $invalidClientId = '00000000-0000-0000-0000-999999999999';

        // Seed initial parties
        $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [['client_id' => $validClientId, 'role' => 'owner']]],
            $this->authHeaders()
        )->assertStatus(200);

        // Attempt replace with one valid + one invalid client
        // Validation runs BEFORE the transaction so it returns 422 without touching the DB
        $this->putJson(
            "/api/v1/projects/{$projectId}/parties",
            ['parties' => [
                ['client_id' => $validClientId, 'role' => 'consultant'],
                ['client_id' => $invalidClientId, 'role' => 'owner'],
            ]],
            $this->authHeaders()
        )->assertStatus(422);

        // Original party must still be intact
        $list = $this->getJson(
            "/api/v1/projects/{$projectId}/parties",
            $this->authHeaders()
        )->json();

        $this->assertCount(1, $list);
        $this->assertEquals('owner', $list[0]['role']);
    }
}

