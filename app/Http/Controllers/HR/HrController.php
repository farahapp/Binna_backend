<?php

declare(strict_types=1);

namespace App\Http\Controllers\HR;

use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\HR\HrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HrController — Thin HTTP layer for HR Workforce Management.
 *
 * Architecture: validate → get actorId + tenantSlug → call HrService → return JSON
 * Business logic stays in HrService ONLY (TASK-010C / CAP-090 V1).
 * No Payroll/WPS (CAP-091 excluded — OD-008 IMPL-BLOCKING).
 */
class HrController extends Controller
{
    public function __construct(
        private readonly HrService $hrService,
    ) {}

    // -------------------------------------------------------------------------
    // Employee Management
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/hr/employees
     * Permission: hr.register
     */
    public function registerEmployee(Request $request): JsonResponse
    {
        if (! $request->user()?->hasPermission('hr.register')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'employee_number'  => ['required', 'string', 'max:50'],
            'first_name'       => ['required', 'string', 'max:100'],
            'last_name'        => ['required', 'string', 'max:100'],
            'employment_type'  => ['sometimes', 'string', 'in:full_time,part_time,contractor,intern'],
            'job_title'        => ['sometimes', 'nullable', 'string', 'max:150'],
            'department'       => ['sometimes', 'nullable', 'string', 'max:150'],
            'hire_date'        => ['sometimes', 'nullable', 'date'],
            'user_id'          => ['sometimes', 'nullable', 'string'],
            'daily_rate'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'hourly_rate'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'         => ['sometimes', 'string', 'size:3'],
        ]);

        $actorId     = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug  = app(TenantContext::class)->slug;

        try {
            $employee = $this->hrService->registerEmployee(
                tenantSlug:     $tenantSlug,
                employeeNumber: $validated['employee_number'],
                firstName:      $validated['first_name'],
                lastName:       $validated['last_name'],
                employmentType: $validated['employment_type'] ?? 'full_time',
                jobTitle:       $validated['job_title'] ?? null,
                department:     $validated['department'] ?? null,
                hireDate:       $validated['hire_date'] ?? null,
                userId:         $validated['user_id'] ?? null,
                dailyRate:      isset($validated['daily_rate']) ? (float) $validated['daily_rate'] : null,
                hourlyRate:     isset($validated['hourly_rate']) ? (float) $validated['hourly_rate'] : null,
                currency:       $validated['currency'] ?? 'SAR',
                actorId:        $actorId,
            );
            return response()->json($employee->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/hr/employees/{id}
     * No special permission required.
     */
    public function getEmployee(Request $request, string $id): JsonResponse
    {
        try {
            $employee = $this->hrService->getEmployee($id);
            return response()->json($employee->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            // HrService throws InvalidArgumentException for "not found" — map to 404
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'Not found')) {
                return response()->json(['message' => $e->getMessage()], 404);
            }
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * GET /api/v1/hr/employees
     * No special permission required.
     */
    public function listActiveEmployees(Request $request): JsonResponse
    {
        $employees = $this->hrService->getActiveEmployees();
        return response()->json($employees->toArray(), 200);
    }

    /**
     * PATCH /api/v1/hr/employees/{id}/terminate
     * Permission: hr.terminate
     */
    public function terminateEmployee(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('hr.terminate')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'termination_date' => ['sometimes', 'nullable', 'date'],
            'notes'            => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $actorId    = (string) $request->user()->id;
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $employee = $this->hrService->terminateEmployee(
                tenantSlug:       $tenantSlug,
                employeeId:       $id,
                terminationDate:  $validated['termination_date'] ?? null,
                notes:            $validated['notes'] ?? null,
                actorId:          $actorId,
            );
            return response()->json($employee->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Project Assignment
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/hr/employees/{id}/project-assignments
     * Permission: hr.assign_project
     */
    public function assignToProject(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('hr.assign_project')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'role'       => ['sometimes', 'nullable', 'string', 'max:150'],
            'start_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $assignment = $this->hrService->assignEmployeeToProject(
                tenantSlug:     $tenantSlug,
                employeeId:     $id,
                projectId:      $validated['project_id'],
                assignedDate:   $validated['start_date'] ?? now()->toDateString(),
                roleOnProject:  $validated['role'] ?? null,
                actorId:        $actorId,
            );
            return response()->json($assignment->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Attendance
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/hr/employees/{id}/attendance
     * No special permission required.
     */
    public function recordAttendance(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'date'         => ['required', 'date'],
            'hours_worked' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:24'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $record = $this->hrService->recordAttendance(
                tenantSlug:     $tenantSlug,
                employeeId:     $id,
                attendanceDate: $validated['date'],
                hoursWorked:    isset($validated['hours_worked']) ? (float) $validated['hours_worked'] : null,
                notes:          $validated['notes'] ?? null,
            );
            $data = $record->toArray();
            // Normalize date field: test expects 'date' as Y-m-d string
            $rawDate = $data['attendance_date'] ?? null;
            $data['date'] = $rawDate ? \Carbon\Carbon::parse($rawDate)->toDateString() : null;
            return response()->json($data, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/hr/employees/{id}/attendance
     * No special permission required.
     */
    public function getAttendance(Request $request, string $id): JsonResponse
    {
        $records = $this->hrService->getAttendanceForEmployee($id);
        return response()->json($records->toArray(), 200);
    }

    // -------------------------------------------------------------------------
    // Leave Requests
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/hr/employees/{id}/leave-requests
     * No special permission required.
     */
    public function submitLeaveRequest(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'leave_type'     => ['required', 'string', 'max:50'],
            'start_date'     => ['required', 'date'],
            'end_date'       => ['required', 'date', 'after_or_equal:start_date'],
            'days_requested' => ['required', 'integer', 'min:1'],
            'reason'         => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $leaveRequest = $this->hrService->submitLeaveRequest(
                tenantSlug:    $tenantSlug,
                employeeId:    $id,
                leaveType:     $validated['leave_type'],
                startDate:     $validated['start_date'],
                endDate:       $validated['end_date'],
                daysRequested: (int) $validated['days_requested'],
                reason:        $validated['reason'] ?? null,
                actorId:       $actorId,
            );
            return response()->json($leaveRequest->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/hr/employees/{id}/leave-requests
     * No special permission required.
     */
    public function listLeaveRequests(Request $request, string $id): JsonResponse
    {
        $leaveRequests = $this->hrService->getLeaveRequestsForEmployee($id);
        return response()->json($leaveRequests->toArray(), 200);
    }

    /**
     * PATCH /api/v1/hr/leave-requests/{id}/approve
     * Permission: hr.approve_leave
     */
    public function approveLeaveRequest(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('hr.approve_leave')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $reviewedBy = (string) $request->user()->id;
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $leaveRequest = $this->hrService->approveLeaveRequest(
                tenantSlug:  $tenantSlug,
                leaveRequestId: $id,
                reviewedBy:  $reviewedBy,
                notes:       $validated['notes'] ?? null,
            );
            return response()->json($leaveRequest->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/hr/leave-requests/{id}/reject
     * Permission: hr.reject_leave
     */
    public function rejectLeaveRequest(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('hr.reject_leave')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $reviewedBy = (string) $request->user()->id;
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $leaveRequest = $this->hrService->rejectLeaveRequest(
                tenantSlug:       $tenantSlug,
                leaveRequestId:   $id,
                reviewedBy:       $reviewedBy,
                rejectionReason:  $validated['rejection_reason'] ?? null,
            );
            return response()->json($leaveRequest->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Cost Allocations
    // -------------------------------------------------------------------------

    /**
     * POST /api/v1/hr/employees/{id}/cost-allocations
     * No special permission required.
     */
    public function recordCostAllocation(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'project_id'   => ['required', 'string'],
            'cost_code_id' => ['required', 'string'],
            'date'         => ['required', 'date'],
            'hours'        => ['required', 'numeric', 'min:0'],
            'amount'       => ['required', 'numeric', 'min:0'],
            'currency'     => ['sometimes', 'string', 'size:3'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $allocation = $this->hrService->recordCostAllocation(
                tenantSlug:     $tenantSlug,
                employeeId:     $id,
                allocationDate: $validated['date'],
                projectId:      $validated['project_id'],
                amount:         (float) $validated['amount'],
                hoursAllocated: (float) $validated['hours'],
                costCodeId:     $validated['cost_code_id'],
                currency:       $validated['currency'] ?? 'SAR',
                notes:          $validated['notes'] ?? null,
                actorId:        $actorId,
            );
            return response()->json($allocation->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/hr/employees/{id}/cost-allocations
     * No special permission required.
     */
    public function listCostAllocations(Request $request, string $id): JsonResponse
    {
        $allocations = $this->hrService->getCostAllocationsForEmployee($id);
        return response()->json($allocations->toArray(), 200);
    }
}
