<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Projects\DailyReport;
use App\Projects\Project;
use App\Projects\ProjectActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for Projects Domain/Data Foundation — TASK-005A.
 *
 * Verifies:
 *  1. All 3 Projects tables exist in tenant schema
 *  2. Projects migrations are in the correct path
 *  3. Model persistence works for key entities
 *  4. Relationships work correctly
 *  5. Tenant isolation — no tenant_id column
 */
class ProjectsFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function projects_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('projects'),
            'projects table must exist in the tenant schema'
        );
    }

    /** @test */
    public function project_activities_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('project_activities'),
            'project_activities table must exist in the tenant schema'
        );
    }

    /** @test */
    public function daily_reports_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('daily_reports'),
            'daily_reports table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function projects_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/projects/2026_09_28_000001_create_projects_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/projects/2026_09_28_000002_create_project_activities_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/projects/2026_09_28_000003_create_daily_reports_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/projects/2026_09_28_000004_create_project_milestones_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/projects/2026_09_28_000005_create_project_team_table.php')
        );
    }

    /** @test */
    public function projects_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_28_000001_create_projects_table.php'),
            'Projects migrations must NOT be in the shared migration path'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function projects_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('projects', 'tenant_id'),
            'projects table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function project_activities_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('project_activities', 'tenant_id'),
            'project_activities table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function daily_reports_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('daily_reports', 'tenant_id'),
            'daily_reports table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function projects_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('projects', 'tenant_slug'),
            'projects table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function project_activities_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('project_activities', 'tenant_slug'),
            'project_activities table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function daily_reports_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('daily_reports', 'tenant_slug'),
            'daily_reports table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Model Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_can_be_persisted(): void
    {
        $project = Project::create([
            'name'        => 'Test Project — Road Construction',
            'status'      => 'draft',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($project->id);
        $this->assertSame('Test Project — Road Construction', $project->name);
        $this->assertSame('draft', $project->status);
        $this->assertSame('test-tenant', $project->tenant_slug);
    }

    /** @test */
    public function project_can_be_persisted_with_nullable_contract_id(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'client_id'   => '00000000-0000-0000-0000-000000000001',
            'contract_id' => null,
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($project->id);
        $this->assertNull($project->contract_id);
        $this->assertSame('Test Project', $project->name);
    }

    /** @test */
    public function project_activity_can_be_persisted_with_project(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $activity = ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Foundation Works',
            'status'      => 'pending',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($activity->id);
        $this->assertSame($project->id, $activity->project_id);
        $this->assertSame('Foundation Works', $activity->name);
        $this->assertSame(0, $activity->progress_percentage);
    }

    /** @test */
    public function project_activity_supports_wbs_hierarchy(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $parent = ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Civil Works',
            'status'      => 'pending',
            'tenant_slug' => 'test-tenant',
        ]);

        $child = ProjectActivity::create([
            'project_id'         => $project->id,
            'name'               => 'Excavation',
            'status'             => 'pending',
            'parent_activity_id' => $parent->id,
            'tenant_slug'        => 'test-tenant',
        ]);

        $this->assertNotNull($child->id);
        $this->assertSame($parent->id, $child->parent_activity_id);
    }

    /** @test */
    public function daily_report_can_be_persisted_with_project(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $report = DailyReport::create([
            'project_id'   => $project->id,
            'report_date'  => '2026-09-28',
            'submitted_by' => '00000000-0000-0000-0000-000000000002',
            'weather'      => 'Sunny',
            'work_done'    => 'Completed foundation pouring.',
            'tenant_slug'  => 'test-tenant',
        ]);

        $this->assertNotNull($report->id);
        $this->assertSame($project->id, $report->project_id);
        $this->assertSame('Sunny', $report->weather);
        $this->assertSame(0, $report->progress_percentage);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_has_many_activities(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Activity A',
            'status'      => 'pending',
            'tenant_slug' => 'test-tenant',
        ]);

        ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Activity B',
            'status'      => 'in_progress',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertCount(2, $project->activities);
    }

    /** @test */
    public function project_has_many_daily_reports(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        DailyReport::create([
            'project_id'   => $project->id,
            'report_date'  => '2026-09-28',
            'submitted_by' => '00000000-0000-0000-0000-000000000002',
            'tenant_slug'  => 'test-tenant',
        ]);

        $this->assertCount(1, $project->dailyReports);
    }

    /** @test */
    public function project_activity_belongs_to_project(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $activity = ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Activity A',
            'status'      => 'pending',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertSame($project->id, $activity->project->id);
    }

    /** @test */
    public function daily_report_belongs_to_project(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $report = DailyReport::create([
            'project_id'   => $project->id,
            'report_date'  => '2026-09-28',
            'submitted_by' => '00000000-0000-0000-0000-000000000002',
            'tenant_slug'  => 'test-tenant',
        ]);

        $this->assertSame($project->id, $report->project->id);
    }

    /** @test */
    public function project_activity_children_relationship_works(): void
    {
        $project = Project::create([
            'name'        => 'Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $parent = ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Civil Works',
            'status'      => 'pending',
            'tenant_slug' => 'test-tenant',
        ]);

        ProjectActivity::create([
            'project_id'         => $project->id,
            'name'               => 'Excavation',
            'status'             => 'pending',
            'parent_activity_id' => $parent->id,
            'tenant_slug'        => 'test-tenant',
        ]);

        ProjectActivity::create([
            'project_id'         => $project->id,
            'name'               => 'Backfill',
            'status'             => 'pending',
            'parent_activity_id' => $parent->id,
            'tenant_slug'        => 'test-tenant',
        ]);

        $this->assertCount(2, $parent->children);
    }

    /** @test */
    public function project_milestones_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('project_milestones'),
            'project_milestones table must exist in the tenant schema'
        );
    }

    /** @test */
    public function project_team_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('project_team'),
            'project_team table must exist in the tenant schema'
        );
    }

    /** @test */
    public function project_milestones_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('project_milestones', 'tenant_id'),
            'project_milestones table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function project_team_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('project_team', 'tenant_id'),
            'project_team table must NOT have tenant_id — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function project_milestone_can_be_created_and_retrieved(): void
    {
        $project = \App\Projects\Project::create([
            'name'        => 'Milestone Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $milestone = \App\Projects\ProjectMilestone::create([
            'project_id'  => $project->id,
            'name'        => 'Foundation Complete',
            'due_date'    => '2026-12-31',
            'status'      => 'pending',
            'tenant_slug' => 'test-tenant',
        ]);

        $found = \App\Projects\ProjectMilestone::on('tenant')->find($milestone->id);
        $this->assertNotNull($found);
        $this->assertSame('Foundation Complete', $found->name);
        $this->assertSame('pending', $found->status);
    }

    /** @test */
    public function project_team_member_can_be_created_and_retrieved(): void
    {
        $project = \App\Projects\Project::create([
            'name'        => 'Team Test Project',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $member = \App\Projects\ProjectTeam::create([
            'project_id'  => $project->id,
            'user_id'     => '00000000-0000-0000-0000-000000000099',
            'role'        => 'engineer',
            'tenant_slug' => 'test-tenant',
        ]);

        $found = \App\Projects\ProjectTeam::on('tenant')->find($member->id);
        $this->assertNotNull($found);
        $this->assertSame('engineer', $found->role);
    }

    /** @test */
    public function project_has_milestones_relationship(): void
    {
        $this->assertTrue(method_exists(\App\Projects\Project::class, 'milestones'));
    }

    /** @test */
    public function project_has_team_relationship(): void
    {
        $this->assertTrue(method_exists(\App\Projects\Project::class, 'team'));
    }
}
