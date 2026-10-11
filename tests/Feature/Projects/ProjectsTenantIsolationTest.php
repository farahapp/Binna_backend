<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Projects\DailyReport;
use App\Projects\Project;
use App\Projects\ProjectActivity;
use App\Projects\ProjectMilestone;
use App\Projects\ProjectTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation tests for Projects module — TASK-005A.
 *
 * Verifies that all Projects models use the tenant connection
 * and do not introduce shared-schema Projects storage.
 */
class ProjectsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function all_projects_models_use_tenant_connection(): void
    {
        $models = [
            Project::class,
            ProjectActivity::class,
            DailyReport::class,
            ProjectMilestone::class,
            ProjectTeam::class,
        ];

        foreach ($models as $modelClass) {
            $instance = new $modelClass();
            $this->assertSame(
                'tenant',
                $instance->getConnectionName(),
                "{$modelClass} must use the per-tenant connection — no shared-schema Projects storage (OD-001)"
            );
        }
    }

    /** @test */
    public function project_data_is_stored_in_tenant_connection(): void
    {
        // Verify that Project records are created on the tenant connection
        $project = Project::create([
            'name'        => 'Isolation Test Project',
            'status'      => 'draft',
            'tenant_slug' => 'isolation-test-tenant',
        ]);

        // Verify it was stored in the tenant schema
        $found = Project::on('tenant')->find($project->id);
        $this->assertNotNull($found, 'Project must be retrievable from the tenant connection');
        $this->assertSame('Isolation Test Project', $found->name);
    }

    /** @test */
    public function project_activity_data_is_stored_in_tenant_connection(): void
    {
        $project = Project::create([
            'name'        => 'Isolation Test Project',
            'status'      => 'active',
            'tenant_slug' => 'isolation-test-tenant',
        ]);

        $activity = ProjectActivity::create([
            'project_id'  => $project->id,
            'name'        => 'Isolation Test Activity',
            'status'      => 'pending',
            'tenant_slug' => 'isolation-test-tenant',
        ]);

        $found = ProjectActivity::on('tenant')->find($activity->id);
        $this->assertNotNull($found, 'ProjectActivity must be retrievable from the tenant connection');
        $this->assertSame('Isolation Test Activity', $found->name);
    }

    /** @test */
    public function daily_report_data_is_stored_in_tenant_connection(): void
    {
        $project = Project::create([
            'name'        => 'Isolation Test Project',
            'status'      => 'active',
            'tenant_slug' => 'isolation-test-tenant',
        ]);

        $report = DailyReport::create([
            'project_id'   => $project->id,
            'report_date'  => '2026-09-28',
            'submitted_by' => '00000000-0000-0000-0000-000000000099',
            'tenant_slug'  => 'isolation-test-tenant',
        ]);

        $found = DailyReport::on('tenant')->find($report->id);
        $this->assertNotNull($found, 'DailyReport must be retrievable from the tenant connection');
        $this->assertSame($project->id, $found->project_id);
    }

    /** @test */
    public function projects_module_does_not_depend_on_accounting_or_finance(): void
    {
        // Structural test: verify Projects models do not import Accounting/Finance namespaces
        $projectFile    = file_get_contents(app_path('Projects/Project.php'));
        $activityFile   = file_get_contents(app_path('Projects/ProjectActivity.php'));
        $reportFile     = file_get_contents(app_path('Projects/DailyReport.php'));

        foreach ([$projectFile, $activityFile, $reportFile] as $fileContent) {
            $this->assertStringNotContainsString(
                'App\\Accounting',
                $fileContent,
                'Projects models must NOT depend on Accounting module (architecture constraint)'
            );
            $this->assertStringNotContainsString(
                'App\\Finance',
                $fileContent,
                'Projects models must NOT depend on Finance module (architecture constraint)'
            );
        }
    }
}
