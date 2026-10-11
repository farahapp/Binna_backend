<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Projects\ProjectsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ProjectsController — Projects HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-06 / TASK-005C):
 *  - Delegates ALL business logic to ProjectsService.
 *  - Does NOT access Projects models directly.
 *  - Does NOT depend on Finance, Accounting, Budget, Cost Control.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum (TASK-001B).
 *
 * TASK-005C — Projects HTTP API Layer
 */
class ProjectsController extends Controller
{
    public function __construct(
        private readonly ProjectsService $projectsService,
    ) {}

    // =========================================================================
    // PUBLIC CONTRACTS
    // =========================================================================

    /**
     * GET /api/v1/projects/{id}
     */
    public function getProject(Request $request, string $id): JsonResponse
    {
        try {
            $project = $this->projectsService->getProject($id);
            return response()->json($project->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
    }

    /**
     * GET /api/v1/projects/{id}/status
     */
    public function getProjectStatus(Request $request, string $id): JsonResponse
    {
        try {
            $status = $this->projectsService->getProjectStatus($id);
            return response()->json($status, 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
    }

    /**
     * GET /api/v1/projects/{id}/team
     */
    public function getProjectTeam(Request $request, string $id): JsonResponse
    {
        $team = $this->projectsService->getProjectTeam($id);
        return response()->json($team, 200);
    }

    /**
     * GET /api/v1/projects/{id}/can-close
     */
    public function canCloseProject(Request $request, string $id): JsonResponse
    {
        $result = $this->projectsService->canCloseProject($id);
        return response()->json($result, 200);
    }

    // =========================================================================
    // PROJECT OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/projects
     */
    public function createProject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'client_id'   => 'nullable|uuid',
            'contract_id' => 'nullable|uuid',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date',
            'manager_id'  => 'nullable|uuid',
        ]);

        $tenantSlug = $request->header('X-Tenant');
        $validated['tenant_slug'] = $tenantSlug;

        try {
            $project = $this->projectsService->createProject($validated);
            return response()->json($project->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/projects/{id}/activate
     */
    public function activateProject(Request $request, string $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $project = $this->projectsService->activateProject($id, $actorId);
            return response()->json($project->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
    }

    /**
     * PATCH /api/v1/projects/{id}/close
     */
    public function closeProject(Request $request, string $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $project = $this->projectsService->closeProject($id, $actorId);
            return response()->json($project->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
    }

    /**
     * PATCH /api/v1/projects/{id}/progress
     */
    public function updateProgress(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'progress_percentage' => 'required|integer|min:0|max:100',
        ]);

        try {
            $actorId = $request->user()?->id;
            $project = $this->projectsService->updateProgress($id, $validated['progress_percentage'], $actorId);
            return response()->json($project->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // ACTIVITY OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/projects/{id}/activities
     */
    public function addActivity(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'description'        => 'nullable|string',
            'planned_start'      => 'nullable|date',
            'planned_end'        => 'nullable|date',
            'parent_activity_id' => 'nullable|uuid',
        ]);

        try {
            $activity = $this->projectsService->addActivity($id, $validated);
            return response()->json($activity->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // DAILY REPORT OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/projects/{id}/daily-reports
     */
    public function submitDailyReport(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'report_date'         => 'required|date',
            'submitted_by'        => 'required|uuid',
            'weather'             => 'nullable|string|max:100',
            'work_done'           => 'nullable|string',
            'issues'              => 'nullable|string',
            'progress_percentage' => 'nullable|integer|min:0|max:100',
        ]);

        try {
            $report = $this->projectsService->submitDailyReport($id, $validated);
            return response()->json($report->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // MILESTONE OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/projects/{id}/milestones
     */
    public function addMilestone(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'nullable|date',
        ]);

        try {
            $milestone = $this->projectsService->addMilestone($id, $validated);
            return response()->json($milestone->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/projects/milestones/{milestoneId}/reached
     */
    public function markMilestoneReached(Request $request, string $milestoneId): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $milestone = $this->projectsService->markMilestoneReached($milestoneId, $actorId);
            return response()->json($milestone->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Milestone not found.'], 404);
        }
    }

    // =========================================================================
    // TEAM OPERATIONS
    // =========================================================================

    /**
     * POST /api/v1/projects/{id}/team
     */
    public function assignTeamMember(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|uuid',
            'role'    => 'nullable|string|max:100',
        ]);

        try {
            $member = $this->projectsService->assignTeamMember($id, $validated['user_id'], $validated['role'] ?? null);
            return response()->json($member->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/projects
     * Paginated list of Projects in the active tenant schema.
     *
     * WEB-001 PHASE 4 — Projects List API
     */
    public function listProjects(Request $request): JsonResponse
    {
        $result = $this->projectsService->listProjects();

        return response()->json([
            'data' => array_map(fn ($p) => $p->toArray(), $result->items()),
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
                'per_page'     => $result->perPage(),
                'total'        => $result->total(),
            ],
        ], 200);
    }
}
