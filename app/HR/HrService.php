<?php

declare(strict_types=1);

namespace App\HR;

use App\Approval\ApprovalService;
use App\Audit\AuditService;
use App\HR\Events\EmployeeRegistered;
use App\HR\Events\LeaveRequestApproved;
use App\HR\Events\LeaveRequestRejected;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * HrService — Application/Service Layer for HR module.
 *
 * Architectural constraints (TASK-010B / A1 MOD-11):
 *  - Implements HR Workforce Management use cases (CAP-090 V1).
 *  - Dispatches Domain Events: EmployeeRegistered, LeaveRequestApproved, LeaveRequestRejected.
 *  - Integrates with AuditService per established conventions.
 *  - Integrates with ApprovalService per OD-007 (Shared Approval Service).
 *  - MUST NOT depend on Payroll/WPS (CAP-091 excluded — OD-008 IMPL-BLOCKING).
 *  - Tenant context resolved by caller via tenant_slug — never cross-tenant access.
 *  - No tenant_id — isolation is physical (Schema-per-Tenant) per OD-001.
 *  - No Auth::user() in business logic — actorId passed as parameter.
 *
 * TASK-010B — HR Application/Service Layer
 */
class HrService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly ApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Employee Management
    // -------------------------------------------------------------------------

    /**
     * registerEmployee — create a new Employee record.
     *
     * @throws InvalidArgumentException If required fields are missing or employee_number is duplicate.
     */
    public function registerEmployee(
        string $tenantSlug,
        string $employeeNumber,
        string $firstName,
        string $lastName,
        string $employmentType = 'full_time',
        string|null $jobTitle = null,
        string|null $department = null,
        string|null $hireDate = null,
        string|null $userId = null,
        float|null $dailyRate = null,
        float|null $hourlyRate = null,
        string $currency = 'SAR',
        string|null $actorId = null,
    ): Employee {
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($employeeNumber)) {
            throw new InvalidArgumentException('employee_number is required.');
        }
        if (empty($firstName)) {
            throw new InvalidArgumentException('first_name is required.');
        }
        if (empty($lastName)) {
            throw new InvalidArgumentException('last_name is required.');
        }

        // Check uniqueness within tenant schema
        if (Employee::where('employee_number', $employeeNumber)->exists()) {
            throw new InvalidArgumentException("employee_number already exists: {$employeeNumber}");
        }

        $employee = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $employeeNumber, $firstName, $lastName,
            $employmentType, $jobTitle, $department, $hireDate,
            $userId, $dailyRate, $hourlyRate, $currency
        ) {
            $employee = Employee::create([
                'tenant_slug'     => $tenantSlug,
                'employee_number' => $employeeNumber,
                'first_name'      => $firstName,
                'last_name'       => $lastName,
                'employment_type' => $employmentType,
                'job_title'       => $jobTitle,
                'department'      => $department,
                'hire_date'       => $hireDate,
                'user_id'         => $userId,
                'daily_rate'      => $dailyRate,
                'hourly_rate'     => $hourlyRate,
                'currency'        => $currency,
                'status'          => 'active',
            ]);

            // Record initial employment record
            EmploymentRecord::create([
                'tenant_slug'    => $tenantSlug,
                'employee_id'    => $employee->id,
                'record_type'    => 'hire',
                'job_title'      => $jobTitle,
                'department'     => $department,
                'daily_rate'     => $dailyRate,
                'hourly_rate'    => $hourlyRate,
                'currency'       => $currency,
                'effective_date' => $hireDate ?? now()->toDateString(),
            ]);

            return $employee;
        });

        $this->auditService->logEvent(
            'hr.employee_registered',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Employee::class,
                'subject_id'   => $employee->id,
                'after'        => ['employee_number' => $employeeNumber, 'status' => 'active'],
            ]
        );

        Event::dispatch(new EmployeeRegistered($employee, $tenantSlug));

        return $employee;
    }

    /**
     * getEmployee — returns Employee by ID.
     *
     * @throws InvalidArgumentException If not found.
     */
    public function getEmployee(string $employeeId): Employee
    {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }

        $employee = Employee::find($employeeId);

        if ($employee === null) {
            throw new InvalidArgumentException("Employee not found: {$employeeId}");
        }

        return $employee;
    }

    /**
     * getActiveEmployees — returns all active employees in the current tenant schema.
     */
    public function getActiveEmployees(): Collection
    {
        return Employee::where('status', 'active')->get();
    }

    /**
     * terminateEmployee — set employee status to terminated.
     *
     * @throws InvalidArgumentException If employee not found or already terminated.
     */
    public function terminateEmployee(
        string $employeeId,
        string $tenantSlug,
        string $terminationDate,
        string|null $notes = null,
        string|null $actorId = null,
    ): Employee {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($terminationDate)) {
            throw new InvalidArgumentException('termination_date is required.');
        }

        $employee = $this->getEmployee($employeeId);

        if ($employee->isTerminated()) {
            throw new InvalidArgumentException("Employee is already terminated: {$employeeId}");
        }

        DB::connection('tenant')->transaction(function () use ($employee, $tenantSlug, $terminationDate, $notes) {
            $employee->update([
                'status'           => 'terminated',
                'termination_date' => $terminationDate,
            ]);

            EmploymentRecord::create([
                'tenant_slug'    => $tenantSlug,
                'employee_id'    => $employee->id,
                'record_type'    => 'termination',
                'effective_date' => $terminationDate,
                'notes'          => $notes,
            ]);
        });

        $this->auditService->logEvent(
            'hr.employee_terminated',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Employee::class,
                'subject_id'   => $employee->id,
                'after'        => ['status' => 'terminated', 'termination_date' => $terminationDate],
            ]
        );

        return $employee->fresh();
    }

    // -------------------------------------------------------------------------
    // Project Assignment
    // -------------------------------------------------------------------------

    /**
     * assignEmployeeToProject — assign an employee to a project.
     *
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function assignEmployeeToProject(
        string $employeeId,
        string $projectId,
        string $tenantSlug,
        string $assignedDate,
        string|null $roleOnProject = null,
        float $allocationPercentage = 100.0,
        string|null $actorId = null,
    ): EmployeeProjectAssignment {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($assignedDate)) {
            throw new InvalidArgumentException('assigned_date is required.');
        }

        // Verify employee exists
        $this->getEmployee($employeeId);

        $assignment = EmployeeProjectAssignment::create([
            'tenant_slug'           => $tenantSlug,
            'employee_id'           => $employeeId,
            'project_id'            => $projectId,
            'role_on_project'       => $roleOnProject,
            'assigned_date'         => $assignedDate,
            'status'                => 'active',
            'allocation_percentage' => $allocationPercentage,
        ]);

        $this->auditService->logEvent(
            'hr.employee_assigned_to_project',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => EmployeeProjectAssignment::class,
                'subject_id'   => $assignment->id,
                'after'        => ['employee_id' => $employeeId, 'project_id' => $projectId],
            ]
        );

        return $assignment;
    }

    // -------------------------------------------------------------------------
    // Attendance
    // -------------------------------------------------------------------------

    /**
     * recordAttendance — record daily attendance for an employee.
     *
     * @throws InvalidArgumentException If required fields are missing or duplicate record.
     */
    public function recordAttendance(
        string $employeeId,
        string $tenantSlug,
        string $attendanceDate,
        string $status = 'present',
        string|null $checkIn = null,
        string|null $checkOut = null,
        float|null $hoursWorked = null,
        string|null $projectId = null,
        string|null $notes = null,
        string|null $recordedBy = null,
    ): AttendanceRecord {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($attendanceDate)) {
            throw new InvalidArgumentException('attendance_date is required.');
        }

        // Verify employee exists
        $this->getEmployee($employeeId);

        // Check for duplicate
        if (AttendanceRecord::where('employee_id', $employeeId)
            ->where('attendance_date', $attendanceDate)
            ->exists()) {
            throw new InvalidArgumentException(
                "Attendance already recorded for employee {$employeeId} on {$attendanceDate}"
            );
        }

        $record = AttendanceRecord::create([
            'tenant_slug'     => $tenantSlug,
            'employee_id'     => $employeeId,
            'attendance_date' => $attendanceDate,
            'status'          => $status,
            'check_in'        => $checkIn,
            'check_out'       => $checkOut,
            'hours_worked'    => $hoursWorked,
            'project_id'      => $projectId,
            'notes'           => $notes,
            'recorded_by'     => $recordedBy,
        ]);

        return $record;
    }

    /**
     * getAttendanceForEmployee — returns attendance records for an employee.
     */
    public function getAttendanceForEmployee(string $employeeId): Collection
    {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }

        return AttendanceRecord::where('employee_id', $employeeId)
            ->orderBy('attendance_date', 'desc')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Leave Management
    // -------------------------------------------------------------------------

    /**
     * submitLeaveRequest — submit a leave request for approval.
     *
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function submitLeaveRequest(
        string $employeeId,
        string $tenantSlug,
        string $leaveType,
        string $startDate,
        string $endDate,
        float $daysRequested,
        string|null $reason = null,
        string|null $actorId = null,
    ): LeaveRequest {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($leaveType)) {
            throw new InvalidArgumentException('leave_type is required.');
        }
        if (empty($startDate)) {
            throw new InvalidArgumentException('start_date is required.');
        }
        if (empty($endDate)) {
            throw new InvalidArgumentException('end_date is required.');
        }
        if ($daysRequested <= 0) {
            throw new InvalidArgumentException('days_requested must be greater than 0.');
        }

        // Verify employee exists and is active
        $employee = $this->getEmployee($employeeId);
        if (!$employee->isActive()) {
            throw new InvalidArgumentException("Employee is not active: {$employeeId}");
        }

        $leaveRequest = DB::connection('tenant')->transaction(function () use (
            $employeeId, $tenantSlug, $leaveType, $startDate, $endDate, $daysRequested, $reason, $actorId
        ) {
            $leaveRequest = LeaveRequest::create([
                'tenant_slug'    => $tenantSlug,
                'employee_id'    => $employeeId,
                'leave_type'     => $leaveType,
                'start_date'     => $startDate,
                'end_date'       => $endDate,
                'days_requested' => $daysRequested,
                'status'         => 'pending',
                'reason'         => $reason,
            ]);

            // Request approval via ApprovalService (OD-007)
            $approvalRequest = $this->approvalService->requestApproval(
                approvableType: LeaveRequest::class,
                approvableId: $leaveRequest->id,
                requestedBy: $actorId ?? $employeeId,
                tenantSlug: $tenantSlug,
            );

            $leaveRequest->update(['approval_request_id' => $approvalRequest->id]);

            return $leaveRequest;
        });

        $this->auditService->logEvent(
            'hr.leave_request_submitted',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => LeaveRequest::class,
                'subject_id'   => $leaveRequest->id,
                'after'        => ['leave_type' => $leaveType, 'status' => 'pending', 'days_requested' => $daysRequested],
            ]
        );

        return $leaveRequest->fresh();
    }

    /**
     * approveLeaveRequest — approve a pending leave request.
     *
     * @throws InvalidArgumentException If leave request not found or not in pending status.
     */
    public function approveLeaveRequest(
        string $leaveRequestId,
        string $tenantSlug,
        string $reviewedBy,
        string|null $notes = null,
    ): LeaveRequest {
        if (empty($leaveRequestId)) {
            throw new InvalidArgumentException('leaveRequestId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($reviewedBy)) {
            throw new InvalidArgumentException('reviewedBy is required.');
        }

        $leaveRequest = LeaveRequest::find($leaveRequestId);
        if ($leaveRequest === null) {
            throw new InvalidArgumentException("LeaveRequest not found: {$leaveRequestId}");
        }
        if (!$leaveRequest->isPending()) {
            throw new InvalidArgumentException(
                "LeaveRequest is not pending (status: {$leaveRequest->status}): {$leaveRequestId}"
            );
        }

        DB::connection('tenant')->transaction(function () use ($leaveRequest, $reviewedBy, $notes) {
            $leaveRequest->update([
                'status'      => 'approved',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            // Grant approval in ApprovalService if approval_request_id exists
            if ($leaveRequest->approval_request_id) {
                $this->approvalService->approve(
                    $leaveRequest->approval_request_id,
                    $reviewedBy,
                );
            }
        });

        $this->auditService->logEvent(
            'hr.leave_request_approved',
            $reviewedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => LeaveRequest::class,
                'subject_id'   => $leaveRequest->id,
                'after'        => ['status' => 'approved'],
            ]
        );

        Event::dispatch(new LeaveRequestApproved($leaveRequest->fresh(), $tenantSlug));

        return $leaveRequest->fresh();
    }

    /**
     * rejectLeaveRequest — reject a pending leave request.
     *
     * @throws InvalidArgumentException If leave request not found or not in pending status.
     */
    public function rejectLeaveRequest(
        string $leaveRequestId,
        string $tenantSlug,
        string $reviewedBy,
        string|null $rejectionReason = null,
    ): LeaveRequest {
        if (empty($leaveRequestId)) {
            throw new InvalidArgumentException('leaveRequestId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($reviewedBy)) {
            throw new InvalidArgumentException('reviewedBy is required.');
        }

        $leaveRequest = LeaveRequest::find($leaveRequestId);
        if ($leaveRequest === null) {
            throw new InvalidArgumentException("LeaveRequest not found: {$leaveRequestId}");
        }
        if (!$leaveRequest->isPending()) {
            throw new InvalidArgumentException(
                "LeaveRequest is not pending (status: {$leaveRequest->status}): {$leaveRequestId}"
            );
        }

        DB::connection('tenant')->transaction(function () use ($leaveRequest, $reviewedBy, $rejectionReason) {
            $leaveRequest->update([
                'status'           => 'rejected',
                'reviewed_by'      => $reviewedBy,
                'reviewed_at'      => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            // Reject in ApprovalService if approval_request_id exists
            if ($leaveRequest->approval_request_id) {
                $this->approvalService->reject(
                    $leaveRequest->approval_request_id,
                    $reviewedBy,
                );
            }
        });

        $this->auditService->logEvent(
            'hr.leave_request_rejected',
            $reviewedBy,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => LeaveRequest::class,
                'subject_id'   => $leaveRequest->id,
                'after'        => ['status' => 'rejected', 'rejection_reason' => $rejectionReason],
            ]
        );

        Event::dispatch(new LeaveRequestRejected($leaveRequest->fresh(), $tenantSlug));

        return $leaveRequest->fresh();
    }

    /**
     * getLeaveRequestsForEmployee — returns all leave requests for an employee.
     */
    public function getLeaveRequestsForEmployee(string $employeeId): Collection
    {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }

        return LeaveRequest::where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Cost Allocation
    // -------------------------------------------------------------------------

    /**
     * recordCostAllocation — record employee cost allocation to a project.
     *
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function recordCostAllocation(
        string $employeeId,
        string $tenantSlug,
        string $allocationDate,
        string $projectId,
        float $amount,
        float $hoursAllocated = 0.0,
        string|null $costCodeId = null,
        string $allocationType = 'daily',
        string $currency = 'SAR',
        string|null $notes = null,
        string|null $actorId = null,
    ): EmployeeCostAllocation {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }
        if (empty($tenantSlug)) {
            throw new InvalidArgumentException('tenant_slug is required.');
        }
        if (empty($allocationDate)) {
            throw new InvalidArgumentException('allocation_date is required.');
        }
        if (empty($projectId)) {
            throw new InvalidArgumentException('projectId must not be empty.');
        }
        if ($amount < 0) {
            throw new InvalidArgumentException('amount must not be negative.');
        }

        // Verify employee exists
        $this->getEmployee($employeeId);

        $allocation = EmployeeCostAllocation::create([
            'tenant_slug'     => $tenantSlug,
            'employee_id'     => $employeeId,
            'allocation_date' => $allocationDate,
            'project_id'      => $projectId,
            'cost_code_id'    => $costCodeId,
            'hours_allocated' => $hoursAllocated,
            'amount'          => $amount,
            'currency'        => $currency,
            'allocation_type' => $allocationType,
            'notes'           => $notes,
        ]);

        $this->auditService->logEvent(
            'hr.cost_allocation_recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => EmployeeCostAllocation::class,
                'subject_id'   => $allocation->id,
                'after'        => ['employee_id' => $employeeId, 'project_id' => $projectId, 'amount' => $amount],
            ]
        );

        return $allocation;
    }

    /**
     * getCostAllocationsForEmployee — returns all cost allocations for an employee.
     */
    public function getCostAllocationsForEmployee(string $employeeId): Collection
    {
        if (empty($employeeId)) {
            throw new InvalidArgumentException('employeeId must not be empty.');
        }

        return EmployeeCostAllocation::where('employee_id', $employeeId)
            ->orderBy('allocation_date', 'desc')
            ->get();
    }
}
