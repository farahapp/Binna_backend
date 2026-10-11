<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use App\Audit\AuditService;
use App\Projects\DailyReport;
use App\Projects\Project;
use App\Projects\ProjectActivity;
use App\Projects\ProjectMilestone;
use App\Projects\ProjectTeam;
use App\Projects\ProjectsService;
use App\Projects\Events\ProjectCreated;
use App\Projects\Events\ProjectClosed;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProjectsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectsService $service;
    private MockInterface $auditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditService = Mockery::mock(AuditService::class);
        $this->auditService->shouldReceive('logEvent')->andReturn(null)->byDefault();
        $this->service = new ProjectsService($this->auditService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Forbidden Dependencies
    // -------------------------------------------------------------------------

    #[Test]
    public function projects_service_has_no_forbidden_dependencies(): void
    {
        $serviceFile = file_get_contents(app_path('Projects/ProjectsService.php'));
        $this->assertStringNotContainsString('App\\Accounting', $serviceFile);
        $this->assertStringNotContainsString('App\\Finance', $serviceFile);
        $this->assertStringNotContainsString('App\\CostControl', $serviceFile);
    }

    // -------------------------------------------------------------------------
    // getProject
    // -------------------------------------------------------------------------

    #[Test]
    public function get_project_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->getProject('');
    }

    #[Test]
    public function get_project_throws_when_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->getProject('00000000-0000-0000-0000-000000000000');
    }

    #[Test]
    public function get_project_returns_project_when_found(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $result = $this->service->getProject($project->id);
        $this->assertSame($project->id, $result->id);
    }

    // -------------------------------------------------------------------------
    // getProjectStatus
    // -------------------------------------------------------------------------

    #[Test]
    public function get_project_status_returns_status_and_progress(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $result = $this->service->getProjectStatus($project->id);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('progress', $result);
        $this->assertSame('active', $result['status']);
    }

    // -------------------------------------------------------------------------
    // getProjectTeam
    // -------------------------------------------------------------------------

    #[Test]
    public function get_project_team_returns_empty_array_when_no_team(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $result = $this->service->getProjectTeam($project->id);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function get_project_team_returns_empty_array_when_project_not_found(): void
    {
        $result = $this->service->getProjectTeam('00000000-0000-0000-0000-000000000000');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // canCloseProject
    // -------------------------------------------------------------------------

    #[Test]
    public function can_close_project_returns_false_for_empty_id(): void
    {
        $result = $this->service->canCloseProject('');
        $this->assertFalse($result['canClose']);
        $this->assertNotEmpty($result['reasons']);
    }

    #[Test]
    public function can_close_project_returns_false_when_not_found(): void
    {
        $result = $this->service->canCloseProject('00000000-0000-0000-0000-000000000000');
        $this->assertFalse($result['canClose']);
        $this->assertContains('project_not_found', $result['reasons']);
    }

    #[Test]
    public function can_close_project_returns_true_for_active_project_with_no_open_items(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $result = $this->service->canCloseProject($project->id);
        $this->assertTrue($result['canClose']);
        $this->assertEmpty($result['reasons']);
    }

    #[Test]
    public function can_close_project_returns_false_for_draft_project(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'draft', 'tenant_slug' => 'test']);
        $result = $this->service->canCloseProject($project->id);
        $this->assertFalse($result['canClose']);
    }

    // -------------------------------------------------------------------------
    // createProject
    // -------------------------------------------------------------------------

    #[Test]
    public function create_project_throws_on_missing_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createProject(['tenant_slug' => 'test']);
    }

    #[Test]
    public function create_project_throws_on_missing_tenant_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createProject(['name' => 'Test']);
    }

    #[Test]
    public function create_project_creates_and_returns_project(): void
    {
        Event::fake();
        $this->auditService->shouldReceive('logEvent')->once();

        $project = $this->service->createProject(['name' => 'New Project', 'tenant_slug' => 'test']);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame('New Project', $project->name);
        Event::assertDispatched(ProjectCreated::class);
    }

    #[Test]
    public function create_project_dispatches_project_created_event(): void
    {
        Event::fake();
        $this->auditService->shouldReceive('logEvent')->once();

        $project = $this->service->createProject(['name' => 'Test', 'tenant_slug' => 'test-tenant']);

        Event::assertDispatched(ProjectCreated::class, function ($event) use ($project) {
            return $event->projectId === $project->id
                && $event->tenantSlug === 'test-tenant';
        });
    }

    // -------------------------------------------------------------------------
    // closeProject
    // -------------------------------------------------------------------------

    #[Test]
    public function close_project_dispatches_project_closed_event(): void
    {
        Event::fake();
        $this->auditService->shouldReceive('logEvent')->once();

        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test-tenant']);
        $this->service->closeProject($project->id);

        Event::assertDispatched(ProjectClosed::class, function ($event) use ($project) {
            return $event->projectId === $project->id;
        });
    }

    // -------------------------------------------------------------------------
    // updateProgress
    // -------------------------------------------------------------------------

    #[Test]
    public function update_progress_throws_on_invalid_percentage(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->updateProgress($project->id, 101);
    }

    #[Test]
    public function update_progress_updates_project(): void
    {
        $this->auditService->shouldReceive('logEvent')->once();
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $result = $this->service->updateProgress($project->id, 50);
        $this->assertSame(50, $result->progress_percentage);
    }

    // -------------------------------------------------------------------------
    // addActivity
    // -------------------------------------------------------------------------

    #[Test]
    public function add_activity_throws_on_missing_name(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->addActivity($project->id, []);
    }

    #[Test]
    public function add_activity_creates_activity(): void
    {
        $this->auditService->shouldReceive('logEvent')->once();
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $activity = $this->service->addActivity($project->id, ['name' => 'Foundation Work']);
        $this->assertInstanceOf(ProjectActivity::class, $activity);
        $this->assertSame('Foundation Work', $activity->name);
    }

    // -------------------------------------------------------------------------
    // submitDailyReport
    // -------------------------------------------------------------------------

    #[Test]
    public function submit_daily_report_throws_on_missing_report_date(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->submitDailyReport($project->id, ['submitted_by' => 'user-id']);
    }

    #[Test]
    public function submit_daily_report_creates_report(): void
    {
        $this->auditService->shouldReceive('logEvent')->once();
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $report = $this->service->submitDailyReport($project->id, [
            'report_date'  => '2026-09-28',
            'submitted_by' => '00000000-0000-0000-0000-000000000099',
        ]);
        $this->assertInstanceOf(DailyReport::class, $report);
    }

    // -------------------------------------------------------------------------
    // addMilestone
    // -------------------------------------------------------------------------

    #[Test]
    public function add_milestone_throws_on_missing_name(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->addMilestone($project->id, []);
    }

    #[Test]
    public function add_milestone_creates_milestone(): void
    {
        $this->auditService->shouldReceive('logEvent')->once();
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $milestone = $this->service->addMilestone($project->id, ['name' => 'Phase 1 Complete']);
        $this->assertInstanceOf(ProjectMilestone::class, $milestone);
        $this->assertSame('Phase 1 Complete', $milestone->name);
    }

    // -------------------------------------------------------------------------
    // markMilestoneReached
    // -------------------------------------------------------------------------

    #[Test]
    public function mark_milestone_reached_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->markMilestoneReached('');
    }

    #[Test]
    public function mark_milestone_reached_updates_status(): void
    {
        $this->auditService->shouldReceive('logEvent')->once();
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $milestone = ProjectMilestone::create([
            'project_id'  => $project->id,
            'tenant_slug' => $project->tenant_slug,
            'name'        => 'M1',
        ]);
        $result = $this->service->markMilestoneReached($milestone->id);
        $this->assertSame('reached', $result->status);
        $this->assertNotNull($result->reached_at);
    }

    // -------------------------------------------------------------------------
    // assignTeamMember
    // -------------------------------------------------------------------------

    #[Test]
    public function assign_team_member_throws_on_empty_user_id(): void
    {
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->assignTeamMember($project->id, '');
    }

    #[Test]
    public function assign_team_member_creates_team_record(): void
    {
        $this->auditService->shouldReceive('logEvent')->once();
        $project = Project::create(['name' => 'Test', 'status' => 'active', 'tenant_slug' => 'test']);
        $member = $this->service->assignTeamMember($project->id, '00000000-0000-0000-0000-000000000099', 'engineer');
        $this->assertInstanceOf(ProjectTeam::class, $member);
        $this->assertSame('engineer', $member->role);
    }

    // -------------------------------------------------------------------------
    // Singleton registration
    // -------------------------------------------------------------------------

    #[Test]
    public function projects_service_resolves_as_singleton(): void
    {
        $a = $this->app->make(ProjectsService::class);
        $b = $this->app->make(ProjectsService::class);
        $this->assertSame($a, $b);
    }
}
