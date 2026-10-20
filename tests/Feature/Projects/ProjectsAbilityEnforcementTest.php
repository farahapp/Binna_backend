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
 * ProjectsAbilityEnforcementTest — SESSION 27D-6
 *
 * Verifies least-privilege Sanctum PAT ability enforcement on:
 *   POST /api/v1/projects/{id}/activities  (requires projects:activities:create)
 *   PATCH /api/v1/projects/activities/{id}/status  (requires projects:activities:update-status)
 *
 * Test matrix:
 *  1. Correct ability → 201/200 (authorised)
 *  2. Missing ability → 403 (authenticated but forbidden)
 *  3. Wrong ability → 403 (authenticated but forbidden)
 *  4. No auth → 401 (unauthenticated)
 *  5. Wildcard '*' token → 201/200 (backward-compatible — tokenCan('*') is true)
 *  6. SPA cookie auth → 201/200 (session guard — tokenCan always true for session users)
 *
 * Constraints (SESSION 27D-6):
 *  - Isolated fixtures only — no operational DB writes, no seeders.
 *  - No real PAT issued against auth-smoke tenant.
 *  - No live activity transitions on production data.
 *  - No Git operations.
 *  - SPA cookie semantics preserved.
 *  - Wildcard tokens NOT revoked — compatibility documented only.
 */
class ProjectsAbilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * CORE-01C-3D: restrict transaction wrapping to 'mysql' only.
     */
    protected array $connectionsToTransact = ['mysql'];

    private string $tenantSlug = 'projects-ability-test';
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->user = $this->createUser();
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('tenant')->table('project_parties')->delete();
            DB::connection('tenant')->table('project_team')->delete();
            DB::connection('tenant')->table('project_milestones')->delete();
            DB::connection('tenant')->table('daily_reports')->delete();
            DB::connection('tenant')->table('project_activities')->delete();
            DB::connection('tenant')->table('projects')->delete();
            DB::connection('tenant')->table('personal_access_tokens')->delete();
            DB::connection('tenant')->table('users')->delete();
            DB::connection('mysql')->table('tenants')->where('slug', $this->tenantSlug)->delete();
        } catch (\Throwable) {}
        DB::purge('tenant');
        parent::tearDown();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function setUpTenant(): void
    {
        DB::connection('mysql')->table('tenants')->insertOrIgnore([
            'slug'        => $this->tenantSlug,
            'name'        => 'Projects Ability Test Tenant',
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

    private function createUser(): User
    {
        return User::on('tenant')->create([
            'name'     => 'Ability Test User',
            'email'    => 'ability-test@binaa.test',
            'password' => Hash::make('password'),
        ]);
    }

    /** Issue a PAT with specific abilities (empty array = no abilities). */
    private function tokenWithAbilities(array $abilities): string
    {
        return $this->user->createToken('test', $abilities)->plainTextToken;
    }

    /** Issue a wildcard PAT (no abilities array = ['*'] in Sanctum). */
    private function wildcardToken(): string
    {
        return $this->user->createToken('wildcard')->plainTextToken;
    }

    private function authHeaders(string $token): array
    {
        return [
            'X-Tenant'      => $this->tenantSlug,
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    private function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenantSlug];
    }

    /** Create a project directly via DB and return its UUID. */
    private function createProjectInDb(): string
    {
        $id = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('tenant')->table('projects')->insert([
            'id'          => $id,
            'name'        => 'Ability Test Project',
            'status'      => 'active',
            'tenant_slug' => $this->tenantSlug,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return $id;
    }

    /** Create an activity directly via DB and return its UUID. */
    private function createActivityInDb(string $projectId): string
    {
        $id = \Illuminate\Support\Str::uuid()->toString();
        DB::connection('tenant')->table('project_activities')->insert([
            'id'          => $id,
            'project_id'  => $projectId,
            'name'        => 'Ability Test Activity',
            'status'      => 'pending',
            'tenant_slug' => $this->tenantSlug,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return $id;
    }

    // =========================================================================
    // 1. CORRECT ABILITY — POST /activities (projects:activities:create)
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Correct ability projects:activities:create → 201.
     */
    public function test_add_activity_with_correct_ability_returns_201(): void
    {
        $projectId = $this->createProjectInDb();
        $token     = $this->tokenWithAbilities(['projects:activities:create']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Ability-Gated Activity'],
            $this->authHeaders($token)
        );

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'Ability-Gated Activity']);
    }

    // =========================================================================
    // 2. MISSING ABILITY — POST /activities → 403
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Missing ability on POST /activities → 403 (authenticated, forbidden).
     */
    public function test_add_activity_with_missing_ability_returns_403(): void
    {
        $projectId = $this->createProjectInDb();
        // Token has projects:read but NOT projects:activities:create
        $token = $this->tokenWithAbilities(['projects:read']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Should Be Forbidden'],
            $this->authHeaders($token)
        );

        $response->assertStatus(403);
    }

    // =========================================================================
    // 3. WRONG ABILITY — POST /activities → 403
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Wrong ability on POST /activities → 403.
     */
    public function test_add_activity_with_wrong_ability_returns_403(): void
    {
        $projectId = $this->createProjectInDb();
        // Token has update-status but NOT create
        $token = $this->tokenWithAbilities(['projects:activities:update-status']);

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Wrong Ability Activity'],
            $this->authHeaders($token)
        );

        $response->assertStatus(403);
    }

    // =========================================================================
    // 4. NO AUTH — POST /activities → 401
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — No auth on POST /activities → 401.
     */
    public function test_add_activity_without_auth_returns_401(): void
    {
        $projectId = $this->createProjectInDb();

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Unauthenticated Activity'],
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // 5. WILDCARD '*' TOKEN — POST /activities → 201 (backward-compatible)
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Wildcard '*' PAT satisfies any ability check → 201.
     * Existing wildcard tokens are NOT revoked; this test documents compatibility.
     */
    public function test_add_activity_with_wildcard_token_returns_201(): void
    {
        $projectId = $this->createProjectInDb();
        $token     = $this->wildcardToken();

        $response = $this->postJson(
            "/api/v1/projects/{$projectId}/activities",
            ['name' => 'Wildcard Token Activity'],
            $this->authHeaders($token)
        );

        $response->assertStatus(201);
    }

    // =========================================================================
    // 6. CORRECT ABILITY — PATCH /activities/{id}/status (projects:activities:update-status)
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Correct ability projects:activities:update-status → 200.
     */
    public function test_update_activity_status_with_correct_ability_returns_200(): void
    {
        $projectId  = $this->createProjectInDb();
        $activityId = $this->createActivityInDb($projectId);
        $token      = $this->tokenWithAbilities(['projects:activities:update-status']);

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'in_progress'],
            $this->authHeaders($token)
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'in_progress']);
    }

    // =========================================================================
    // 7. MISSING ABILITY — PATCH /activities/{id}/status → 403
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Missing ability on PATCH /activities/{id}/status → 403.
     */
    public function test_update_activity_status_with_missing_ability_returns_403(): void
    {
        $projectId  = $this->createProjectInDb();
        $activityId = $this->createActivityInDb($projectId);
        // Token has create but NOT update-status
        $token = $this->tokenWithAbilities(['projects:activities:create']);

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'in_progress'],
            $this->authHeaders($token)
        );

        $response->assertStatus(403);
    }

    // =========================================================================
    // 8. NO AUTH — PATCH /activities/{id}/status → 401
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — No auth on PATCH /activities/{id}/status → 401.
     */
    public function test_update_activity_status_without_auth_returns_401(): void
    {
        $projectId  = $this->createProjectInDb();
        $activityId = $this->createActivityInDb($projectId);

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'in_progress'],
            $this->tenantHeaders()
        );

        $response->assertStatus(401);
    }

    // =========================================================================
    // 9. WILDCARD '*' TOKEN — PATCH /activities/{id}/status → 200 (backward-compatible)
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Wildcard '*' PAT satisfies update-status ability check → 200.
     */
    public function test_update_activity_status_with_wildcard_token_returns_200(): void
    {
        $projectId  = $this->createProjectInDb();
        $activityId = $this->createActivityInDb($projectId);
        $token      = $this->wildcardToken();

        $response = $this->patchJson(
            "/api/v1/projects/activities/{$activityId}/status",
            ['status' => 'in_progress'],
            $this->authHeaders($token)
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'in_progress']);
    }

    // =========================================================================
    // 10. SPA COOKIE AUTH — POST /activities → 201 (session guard, no PAT abilities)
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — SPA cookie (session) auth bypasses tokenCan() → 201.
     *
     * Sanctum's 'abilities' middleware calls $request->user()->tokenCan($ability).
     * For session-authenticated users (no PAT), tokenCan() always returns true
     * because there is no token to restrict. This preserves SPA cookie semantics.
     *
     * This test uses actingAs() with the web guard to simulate SPA session auth
     * without requiring a real stateful-domain request.
     */
    public function test_add_activity_with_spa_session_auth_returns_201(): void
    {
        $projectId = $this->createProjectInDb();

        // Simulate SPA session auth: actingAs with web guard — no PAT issued.
        $response = $this->actingAs($this->user, 'web')
            ->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->postJson(
                "/api/v1/projects/{$projectId}/activities",
                ['name' => 'SPA Session Activity']
            );

        // SPA session auth must not be blocked by ability middleware.
        $response->assertStatus(201);
    }

    // =========================================================================
    // 11. SPA COOKIE AUTH — PATCH /activities/{id}/status → 200
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — SPA cookie auth on PATCH /activities/{id}/status → 200.
     */
    public function test_update_activity_status_with_spa_session_auth_returns_200(): void
    {
        $projectId  = $this->createProjectInDb();
        $activityId = $this->createActivityInDb($projectId);

        $response = $this->actingAs($this->user, 'web')
            ->withHeaders(['X-Tenant' => $this->tenantSlug])
            ->patchJson(
                "/api/v1/projects/activities/{$activityId}/status",
                ['status' => 'in_progress']
            );

        $response->assertStatus(200);
        $response->assertJsonFragment(['status' => 'in_progress']);
    }

    // =========================================================================
    // 12. TENANT ISOLATION — ability-gated route still enforces tenant_slug
    // =========================================================================

    /**
     * @test
     * SESSION 27D-6 — Ability-gated PATCH still enforces tenant isolation → 404.
     *
     * Even with the correct ability, an activity belonging to a different tenant
     * must return 404 (no information leakage). Ability enforcement does not
     * weaken the existing compound (id + tenant_slug) predicate.
     */
    public function test_update_activity_status_cross_tenant_with_correct_ability_returns_404(): void
    {
        // Create activity under the primary test tenant (Tenant A).
        $projectId  = $this->createProjectInDb();
        $activityId = $this->createActivityInDb($projectId);

        // Token with correct ability but issued for Tenant A user.
        $token = $this->tokenWithAbilities(['projects:activities:update-status']);

        // Mock Tenant B resolver (same pattern as existing cross-tenant isolation test).
        $tenantBSlug  = 'projects-ability-test-tenant-b';
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
                            id: 99998,
                            slug: $this->tenantBSlug,
                            name: 'Tenant B -- Ability Isolation Test',
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

        try {
            $response = $this->patchJson(
                "/api/v1/projects/activities/{$activityId}/status",
                ['status' => 'in_progress'],
                [
                    'X-Tenant'      => $tenantBSlug,
                    'Authorization' => 'Bearer ' . $token,
                ]
            );

            // Must be 404 — activity not found in Tenant B's scope.
            $response->assertStatus(404);
            $response->assertJsonFragment(['message' => 'Activity not found.']);

            // Activity must remain unmutated.
            $this->assertDatabaseHas('project_activities', [
                'id'     => $activityId,
                'status' => 'pending',
            ], 'tenant');
        } finally {
            $this->app->instance(\App\Foundation\TenantContextResolver::class, $realResolver);
        }
    }
}
