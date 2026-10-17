<?php

declare(strict_types=1);

namespace App\Projects;

use App\Audit\AuditService;
use App\CRM\CRMService;
use App\Projects\Events\ProjectClosed;
use App\Projects\Events\ProjectCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * ProjectsService — Application/Service Layer for Projects module.
 *
 * Architectural constraints (TASK-005B / A1 MOD-06):
 *  - Implements Public Contracts: GetProject, GetProjectStatus, GetProjectTeam, CanCloseProject
 *  - Dispatches Domain Events: ProjectCreated (E-001), ProjectClosed (E-002)
 *  - Integrates with AuditService per NEW-003
 *  - MUST NOT depend on Accounting, Finance, Budget, Cost Control
 *  - Tenant context resolved by TenantContext mechanism (OD-001)
 *
 * TASK-005B — Projects Application/Service Layer
 */
class ProjectsService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // Public Contracts (A4 §4.3)
    // -------------------------------------------------------------------------

    /**
     * GetProject — returns Project data.
     * Throws InvalidArgumentException if projectId is empty or project not found.
     */
    public function getProject(string $projectId): Project
    {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        // M2.1B: eager-load activities and milestones so toArray() includes them
        // CORE-01C-2: also eager-load parties
        $project = Project::with(['activities', 'milestones', 'parties'])->find($projectId);

        if ($project === null) {
            throw new InvalidArgumentException("Project not found: {$projectId}");
        }

        return $project;
    }

    /**
     * GetProjectStatus — returns status and progress percentage.
     * Throws InvalidArgumentException if not found.
     */
    public function getProjectStatus(string $projectId): array
    {
        $project = $this->getProject($projectId);

        return [
            'status'   => $project->status,
            'progress' => $project->progress_percentage ?? 0,
        ];
    }

    /**
     * GetProjectTeam — returns list of team members.
     * Returns empty array if no team members.
     */
    public function getProjectTeam(string $projectId): array
    {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        $project = Project::find($projectId);

        if ($project === null) {
            return [];
        }

        return $project->team()->get()->toArray();
    }

    /**
     * CanCloseProject — policy check for project closure.
     * Never throws — returns false + reasons if cannot close.
     */
    public function canCloseProject(string $projectId): array
    {
        if (empty($projectId)) {
            return ['canClose' => false, 'reasons' => ['projectId must not be empty']];
        }

        $project = Project::find($projectId);

        if ($project === null) {
            return ['canClose' => false, 'reasons' => ['project_not_found']];
        }

        $reasons = [];

        if (!in_array($project->status, ['active', 'on_hold'], true)) {
            $reasons[] = "project_status_is_{$project->status}";
        }

        // Check for open activities
        $openActivities = $project->activities()
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        if ($openActivities > 0) {
            $reasons[] = "has_{$openActivities}_open_activities";
        }

        // Check for pending milestones
        $pendingMilestones = $project->milestones()
            ->where('status', 'pending')
            ->count();

        if ($pendingMilestones > 0) {
            $reasons[] = "has_{$pendingMilestones}_pending_milestones";
        }

        return [
            'canClose' => empty($reasons),
            'reasons'  => $reasons,
        ];
    }

    // -------------------------------------------------------------------------
    // Application Operations
    // -------------------------------------------------------------------------

    /**
     * Create a new project.
     * Dispatches ProjectCreated event.
     */
    public function createProject(array $attributes): Project
    {
        if (empty($attributes['name'])) {
            throw new InvalidArgumentException('name is required');
        }

        if (empty($attributes['tenant_slug'])) {
            throw new InvalidArgumentException('tenant_slug is required');
        }

        $project = Project::create($attributes);

        $this->auditService->logEvent('projects.project.created', null, [
            'tenant_slug' => $project->tenant_slug,
            'subject_type' => 'project',
            'subject_id'  => $project->id,
            'after'       => ['name' => $project->name],
        ]);

        Event::dispatch(new ProjectCreated(
            projectId:  $project->id,
            tenantSlug: $project->tenant_slug,
            occurredAt: new \DateTimeImmutable(),
        ));

        return $project;
    }

    /**
     * Activate a project (draft → active).
     */
    public function activateProject(string $projectId, ?string $actorId = null): Project
    {
        $project = $this->getProject($projectId);

        $project->status = 'active';
        $project->save();

        $this->auditService->logEvent('projects.project.activated', $actorId, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'project',
            'subject_id'   => $project->id,
        ]);

        return $project;
    }

    /**
     * Close a project.
     * Dispatches ProjectClosed event.
     */
    public function closeProject(string $projectId, ?string $actorId = null): Project
    {
        $project = $this->getProject($projectId);

        $project->status = 'closed';
        $project->save();

        $this->auditService->logEvent('projects.project.closed', $actorId, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'project',
            'subject_id'   => $project->id,
        ]);

        Event::dispatch(new ProjectClosed(
            projectId:  $project->id,
            tenantSlug: $project->tenant_slug,
            occurredAt: new \DateTimeImmutable(),
        ));

        return $project;
    }

    /**
     * Update project progress percentage.
     */
    public function updateProgress(string $projectId, int $progressPercentage, ?string $actorId = null): Project
    {
        if ($progressPercentage < 0 || $progressPercentage > 100) {
            throw new InvalidArgumentException('progressPercentage must be between 0 and 100');
        }

        $project = $this->getProject($projectId);

        $project->progress_percentage = $progressPercentage;
        $project->save();

        $this->auditService->logEvent('projects.project.progress_updated', $actorId, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'project',
            'subject_id'   => $project->id,
            'after'        => ['progress_percentage' => $progressPercentage],
        ]);

        return $project;
    }

    /**
     * Add an activity to a project.
     */
    public function addActivity(string $projectId, array $attributes): ProjectActivity
    {
        if (empty($attributes['name'])) {
            throw new InvalidArgumentException('name is required');
        }

        $project = $this->getProject($projectId);

        $activity = ProjectActivity::create(array_merge($attributes, [
            'project_id'  => $project->id,
            'tenant_slug' => $project->tenant_slug,
        ]));

        $this->auditService->logEvent('projects.activity.created', null, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'project_activity',
            'subject_id'   => $activity->id,
        ]);

        return $activity;
    }

    /**
     * Submit a daily report for a project.
     */
    public function submitDailyReport(string $projectId, array $attributes): DailyReport
    {
        if (empty($attributes['report_date'])) {
            throw new InvalidArgumentException('report_date is required');
        }

        if (empty($attributes['submitted_by'])) {
            throw new InvalidArgumentException('submitted_by is required');
        }

        $project = $this->getProject($projectId);

        $report = DailyReport::create(array_merge($attributes, [
            'project_id'  => $project->id,
            'tenant_slug' => $project->tenant_slug,
        ]));

        $this->auditService->logEvent('projects.daily_report.submitted', null, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'daily_report',
            'subject_id'   => $report->id,
        ]);

        return $report;
    }

    /**
     * Add a milestone to a project.
     */
    public function addMilestone(string $projectId, array $attributes): ProjectMilestone
    {
        if (empty($attributes['name'])) {
            throw new InvalidArgumentException('name is required');
        }

        $project = $this->getProject($projectId);

        $milestone = ProjectMilestone::create(array_merge($attributes, [
            'project_id'  => $project->id,
            'tenant_slug' => $project->tenant_slug,
        ]));

        $this->auditService->logEvent('projects.milestone.created', null, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'project_milestone',
            'subject_id'   => $milestone->id,
        ]);

        return $milestone;
    }

    /**
     * Mark a milestone as reached.
     */
    public function markMilestoneReached(string $milestoneId, ?string $actorId = null): ProjectMilestone
    {
        if (empty($milestoneId)) {
            throw new InvalidArgumentException('milestoneId must not be empty');
        }

        $milestone = ProjectMilestone::find($milestoneId);

        if ($milestone === null) {
            throw new InvalidArgumentException("Milestone not found: {$milestoneId}");
        }

        $milestone->status     = 'reached';
        $milestone->reached_at = now();
        $milestone->save();

        $this->auditService->logEvent('projects.milestone.reached', $actorId, [
            'tenant_slug'  => $milestone->tenant_slug,
            'subject_type' => 'project_milestone',
            'subject_id'   => $milestone->id,
        ]);

        return $milestone;
    }

    /**
     * Assign a team member to a project.
     */
    public function assignTeamMember(string $projectId, string $userId, ?string $role = null): ProjectTeam
    {
        if (empty($userId)) {
            throw new InvalidArgumentException('userId must not be empty');
        }

        $project = $this->getProject($projectId);

        $member = ProjectTeam::create([
            'project_id'  => $project->id,
            'user_id'     => $userId,
            'role'        => $role,
            'tenant_slug' => $project->tenant_slug,
            'assigned_at' => now(),
        ]);

        $this->auditService->logEvent('projects.team.member_assigned', null, [
            'tenant_slug'  => $project->tenant_slug,
            'subject_type' => 'project_team',
            'subject_id'   => $member->id,
        ]);

        return $member;
    }

    /**
     * ListProjects — paginated list of Projects in the active tenant schema.
     *
     * Tenant isolation: Project uses connection='tenant' (OD-001).
     * WEB-001 PHASE 4 — Projects List API
     */
    public function listProjects(int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Project::orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * ListDailyReports — paginated list of daily reports for a project.
     *
     * Tenant isolation: DailyReport uses connection='tenant' (OD-001).
     * The project is resolved via getProject() which throws if not found or
     * if it does not belong to the active tenant schema — enforcing cross-tenant
     * isolation automatically.
     *
     * M2.3A-FIX — BLOCKER-M23A-001
     */
    public function listDailyReports(string $projectId, int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // getProject() throws InvalidArgumentException if the project is not
        // found in the current tenant schema — this enforces tenant isolation.
        $project = $this->getProject($projectId);

        return DailyReport::where('project_id', $project->id)
            ->orderByDesc('report_date')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    // -------------------------------------------------------------------------
    // Party Operations (CORE-01C)
    // -------------------------------------------------------------------------

    /** Allowed party roles. */
    private const ALLOWED_PARTY_ROLES = ['owner', 'consultant', 'main_contractor'];

    /**
     * ListProjectParties — returns the parties list for a project.
     *
     * Tenant isolation: getProject() throws if the project is not in the
     * active tenant schema.
     *
     * CORE-01C-2
     */
    public function listProjectParties(string $projectId): array
    {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        $project = Project::find($projectId);

        if ($project === null) {
            return [];
        }

        return $project->parties()->get()->toArray();
    }

    /**
     * ReplaceProjectParties — atomically replaces all parties for a project.
     *
     * Validates:
     *  - Project exists in current tenant schema.
     *  - Each role is one of ALLOWED_PARTY_ROLES.
     *  - No duplicate (client_id, role) pairs in the input.
     *  - Each client_id exists and is active via CRMService::validateClient().
     *
     * Atomicity: wrapped in a DB Transaction. Audit log is written only after
     * the transaction commits to avoid a successful audit entry for a
     * rolled-back operation.
     *
     * CORE-01C-2
     *
     * @param  array<array{client_id: string, role: string}>  $parties
     * @throws InvalidArgumentException
     */
    public function replaceProjectParties(
        string $projectId,
        array $parties,
        ?string $actorId,
        string $tenantSlug
    ): array {
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty');
        }

        // ── 1. Resolve project (tenant isolation) ────────────────────────────
        $project = $this->getProject($projectId);

        // ── 2. Validate input ─────────────────────────────────────────────────
        $seen = [];
        foreach ($parties as $index => $entry) {
            $clientId = $entry['client_id'] ?? null;
            $role     = $entry['role'] ?? null;

            if (empty($clientId) || empty($role)) {
                throw new InvalidArgumentException(
                    "parties[{$index}]: client_id and role are required"
                );
            }

            if (!in_array($role, self::ALLOWED_PARTY_ROLES, true)) {
                throw new InvalidArgumentException(
                    "parties[{$index}]: role '{$role}' is not allowed. "
                    . 'Allowed: ' . implode(', ', self::ALLOWED_PARTY_ROLES)
                );
            }

            $key = "{$clientId}|{$role}";
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(
                    "parties[{$index}]: duplicate (client_id, role) pair"
                );
            }
            $seen[$key] = true;

            // Validate client via CRM contract — no direct cross-module model access
            $crmService     = app(CRMService::class);
            $validation     = $crmService->validateClient($clientId);
            if (!$validation['isValid']) {
                throw new InvalidArgumentException(
                    "parties[{$index}]: client_id '{$clientId}' — {$validation['reason']}"
                );
            }
        }

        // ── 3. Capture before-state for audit ────────────────────────────────
        $before = $project->parties()->get()->toArray();

        // ── 4. Atomic replace ─────────────────────────────────────────────────
        $newParties = DB::connection('tenant')->transaction(function () use ($project, $parties, $tenantSlug) {
            $project->parties()->delete();

            $created = [];
            foreach ($parties as $entry) {
                $created[] = ProjectParty::create([
                    'project_id'  => $project->id,
                    'client_id'   => $entry['client_id'],
                    'role'        => $entry['role'],
                    'tenant_slug' => $tenantSlug,
                ]);
            }

            return $created;
        });

        // ── 5. Audit — only after successful commit ───────────────────────────
        $this->auditService->logEvent('projects.project.parties_replaced', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'project',
            'subject_id'   => $project->id,
            'before'       => $before,
            'after'        => array_map(fn ($p) => $p->toArray(), $newParties),
        ]);

        return array_map(fn ($p) => $p->toArray(), $newParties);
    }
}
