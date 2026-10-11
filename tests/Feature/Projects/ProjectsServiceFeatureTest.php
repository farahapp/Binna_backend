<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Projects\Project;
use App\Projects\ProjectsService;
use App\Projects\Events\ProjectCreated;
use App\Projects\Events\ProjectClosed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProjectsServiceFeatureTest extends TestCase
{
    use RefreshDatabase;

    private ProjectsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ProjectsService::class);
    }

    public function test_full_project_lifecycle(): void
    {
        Event::fake();

        // Create
        $project = $this->service->createProject([
            'name'        => 'Construction Project Alpha',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertSame('draft', $project->status);
        Event::assertDispatched(ProjectCreated::class);

        // Activate
        $project = $this->service->activateProject($project->id);
        $this->assertSame('active', $project->status);

        // Add activity
        $activity = $this->service->addActivity($project->id, ['name' => 'Foundation Work']);
        $this->assertSame($project->id, $activity->project_id);

        // Add milestone
        $milestone = $this->service->addMilestone($project->id, ['name' => 'Phase 1 Complete']);
        $this->assertSame('pending', $milestone->status);

        // Submit daily report
        $report = $this->service->submitDailyReport($project->id, [
            'report_date'  => '2026-09-28',
            'submitted_by' => '00000000-0000-0000-0000-000000000099',
        ]);
        $this->assertSame($project->id, $report->project_id);

        // Update progress
        $project = $this->service->updateProgress($project->id, 50);
        $this->assertSame(50, $project->progress_percentage);

        // Close
        $project = $this->service->closeProject($project->id);
        $this->assertSame('closed', $project->status);
        Event::assertDispatched(ProjectClosed::class);
    }

    public function test_get_project_contracts_work(): void
    {
        $project = $this->service->createProject([
            'name'        => 'Test Project',
            'tenant_slug' => 'test-tenant',
        ]);

        // GetProject
        $found = $this->service->getProject($project->id);
        $this->assertSame($project->id, $found->id);

        // GetProjectStatus
        $status = $this->service->getProjectStatus($project->id);
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('progress', $status);

        // GetProjectTeam
        $team = $this->service->getProjectTeam($project->id);
        $this->assertIsArray($team);

        // CanCloseProject
        $canClose = $this->service->canCloseProject($project->id);
        $this->assertArrayHasKey('canClose', $canClose);
        $this->assertArrayHasKey('reasons', $canClose);
    }

    public function test_tenant_isolation_in_service(): void
    {
        $project = $this->service->createProject([
            'name'        => 'Tenant Test Project',
            'tenant_slug' => 'tenant-a',
        ]);

        $found = Project::on('tenant')->find($project->id);
        $this->assertNotNull($found);
        $this->assertSame('tenant-a', $found->tenant_slug);
    }
}
