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
            DB::connection('tenant')->table('project_team')->delete();
            DB::connection('tenant')->table('project_milestones')->delete();
            DB::connection('tenant')->table('daily_reports')->delete();
            DB::connection('tenant')->table('project_activities')->delete();
            DB::connection('tenant')->table('projects')->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
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
        Config::set('database.connections.tenant.host', '127.0.0.1');
        Config::set('database.connections.tenant.port', '3306');
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
}
