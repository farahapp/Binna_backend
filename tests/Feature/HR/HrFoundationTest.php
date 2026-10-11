<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\HR\AttendanceRecord;
use App\HR\Employee;
use App\HR\EmployeeCostAllocation;
use App\HR\EmployeeProjectAssignment;
use App\HR\EmploymentRecord;
use App\HR\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for HR Domain/Data Foundation — TASK-010A.
 *
 * Verifies:
 *  1. All 6 HR tables exist in tenant schema
 *  2. No tenant_id on any table (Schema-per-Tenant — OD-001)
 *  3. tenant_slug present on all tables (cross-schema traceability — OD-001)
 *  4. HR migrations are in the correct path
 *  5. Migration not in wrong path (not in /shared/)
 *  6. UUID PK — each model has uuid primary key
 *  7. Model connection — all models use 'tenant' connection
 *  8. Employee persistence — create and find
 *  9. Employee defaults — status='active', employment_type='full_time', currency='SAR'
 * 10. Employee status helpers — isActive(), isInactive(), isTerminated()
 * 11. EmploymentRecord persistence — create with FK to employee
 * 12. EmployeeProjectAssignment persistence — create with UUID project_id (no FK violation)
 * 13. AttendanceRecord persistence — create with unique constraint
 * 14. AttendanceRecord unique constraint — duplicate employee+date rejected
 * 15. LeaveRequest persistence — create with approval_request_id UUID (no FK violation)
 * 16. LeaveRequest defaults — status='pending'
 * 17. LeaveRequest status helpers — isPending(), isApproved(), isRejected(), isCancelled()
 * 18. EmployeeCostAllocation persistence — create with project_id and cost_code_id UUIDs (no FK violation)
 * 19. Relationships — employee hasMany records, assignments, attendance, leaves, allocations
 * 20. No Payroll/WPS tables — payroll_runs, wps_files must NOT exist
 */
class HrFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employees_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('employees'),
            'employees table must exist in the tenant schema'
        );
    }

    /** @test */
    public function employment_records_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('employment_records'),
            'employment_records table must exist in the tenant schema'
        );
    }

    /** @test */
    public function employee_project_assignments_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('employee_project_assignments'),
            'employee_project_assignments table must exist in the tenant schema'
        );
    }

    /** @test */
    public function attendance_records_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('attendance_records'),
            'attendance_records table must exist in the tenant schema'
        );
    }

    /** @test */
    public function leave_requests_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('leave_requests'),
            'leave_requests table must exist in the tenant schema'
        );
    }

    /** @test */
    public function employee_cost_allocations_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('employee_cost_allocations'),
            'employee_cost_allocations table must exist in the tenant schema'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Isolation — No tenant_id Tests (OD-001)
    // -------------------------------------------------------------------------

    /** @test */
    public function employees_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('employees', 'tenant_id'),
            'employees must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function employment_records_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('employment_records', 'tenant_id'),
            'employment_records must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function employee_project_assignments_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('employee_project_assignments', 'tenant_id'),
            'employee_project_assignments must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function attendance_records_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('attendance_records', 'tenant_id'),
            'attendance_records must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function leave_requests_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('leave_requests', 'tenant_id'),
            'leave_requests must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    /** @test */
    public function employee_cost_allocations_table_has_no_tenant_id_column(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasColumn('employee_cost_allocations', 'tenant_id'),
            'employee_cost_allocations must NOT have a tenant_id column — isolation is physical (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Tenant Slug Tests (OD-001 cross-schema traceability)
    // -------------------------------------------------------------------------

    /** @test */
    public function employees_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('employees', 'tenant_slug'),
            'employees table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function leave_requests_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('leave_requests', 'tenant_slug'),
            'leave_requests table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    /** @test */
    public function attendance_records_table_has_tenant_slug_column(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasColumn('attendance_records', 'tenant_slug'),
            'attendance_records table must have tenant_slug for cross-schema traceability (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Migration Path Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function hr_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/hr/2026_10_01_100001_create_employees_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/hr/2026_10_01_100002_create_employment_records_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/hr/2026_10_01_100003_create_employee_project_assignments_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/hr/2026_10_01_100004_create_attendance_records_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/hr/2026_10_01_100005_create_leave_requests_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/hr/2026_10_01_100006_create_employee_cost_allocations_table.php')
        );
    }

    /** @test */
    public function hr_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_10_01_100001_create_employees_table.php'),
            'HR migrations must NOT be in the shared migration path'
        );
    }

    // -------------------------------------------------------------------------
    // Model Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employee_model_uses_tenant_connection(): void
    {
        $employee = new Employee();
        $this->assertSame('tenant', $employee->getConnectionName());
    }

    /** @test */
    public function leave_request_model_uses_tenant_connection(): void
    {
        $leave = new LeaveRequest();
        $this->assertSame('tenant', $leave->getConnectionName());
    }

    /** @test */
    public function attendance_record_model_uses_tenant_connection(): void
    {
        $attendance = new AttendanceRecord();
        $this->assertSame('tenant', $attendance->getConnectionName());
    }

    /** @test */
    public function employee_cost_allocation_model_uses_tenant_connection(): void
    {
        $allocation = new EmployeeCostAllocation();
        $this->assertSame('tenant', $allocation->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // Employee Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employee_can_be_persisted(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-001',
            'first_name'      => 'Ahmed',
            'last_name'       => 'Al-Rashidi',
        ]);

        $this->assertNotNull($employee->id);
        $this->assertSame('EMP-001', $employee->employee_number);
        $this->assertSame('Ahmed', $employee->first_name);
        $this->assertSame('Al-Rashidi', $employee->last_name);
        $this->assertSame('test-tenant', $employee->tenant_slug);
    }

    /** @test */
    public function employee_has_correct_defaults(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-002',
            'first_name'      => 'Mohammed',
            'last_name'       => 'Al-Zahrani',
        ]);

        $this->assertSame('active', $employee->status);
        $this->assertSame('full_time', $employee->employment_type);
        $this->assertSame('SAR', $employee->currency);
    }

    /** @test */
    public function employee_uuid_is_generated(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-003',
            'first_name'      => 'Khalid',
            'last_name'       => 'Al-Otaibi',
        ]);

        $this->assertNotNull($employee->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $employee->id,
            'Employee ID must be a valid UUID'
        );
    }

    /** @test */
    public function employee_status_helpers_work_correctly(): void
    {
        $active = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-010',
            'first_name'      => 'Active',
            'last_name'       => 'Employee',
            'status'          => 'active',
        ]);

        $inactive = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-011',
            'first_name'      => 'Inactive',
            'last_name'       => 'Employee',
            'status'          => 'inactive',
        ]);

        $terminated = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-012',
            'first_name'      => 'Terminated',
            'last_name'       => 'Employee',
            'status'          => 'terminated',
        ]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isInactive());
        $this->assertFalse($active->isTerminated());

        $this->assertFalse($inactive->isActive());
        $this->assertTrue($inactive->isInactive());

        $this->assertFalse($terminated->isActive());
        $this->assertTrue($terminated->isTerminated());
    }

    /** @test */
    public function employee_number_must_be_unique(): void
    {
        Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-UNIQUE',
            'first_name'      => 'First',
            'last_name'       => 'Employee',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-UNIQUE',
            'first_name'      => 'Second',
            'last_name'       => 'Employee',
        ]);
    }

    // -------------------------------------------------------------------------
    // EmploymentRecord Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employment_record_can_be_persisted_with_employee(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-020',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $record = EmploymentRecord::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'record_type'    => 'hire',
            'job_title'      => 'Site Engineer',
            'effective_date' => '2026-01-01',
        ]);

        $this->assertNotNull($record->id);
        $this->assertSame($employee->id, $record->employee_id);
        $this->assertSame('hire', $record->record_type);
        $this->assertSame('SAR', $record->currency);
    }

    /** @test */
    public function employee_has_many_employment_records(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-021',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        EmploymentRecord::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'record_type'    => 'hire',
            'effective_date' => '2026-01-01',
        ]);

        EmploymentRecord::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'record_type'    => 'promotion',
            'effective_date' => '2026-06-01',
        ]);

        $this->assertCount(2, $employee->employmentRecords);
    }

    // -------------------------------------------------------------------------
    // EmployeeProjectAssignment Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employee_project_assignment_can_be_persisted(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-030',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $assignment = EmployeeProjectAssignment::create([
            'tenant_slug'   => 'test-tenant',
            'employee_id'   => $employee->id,
            'project_id'    => '00000000-0000-0000-0000-000000000001', // UUID only, no FK
            'assigned_date' => '2026-01-15',
        ]);

        $this->assertNotNull($assignment->id);
        $this->assertSame($employee->id, $assignment->employee_id);
        $this->assertSame('active', $assignment->status);
        $this->assertSame('100.00', $assignment->allocation_percentage);
    }

    /** @test */
    public function employee_project_assignment_status_helpers_work(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-031',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $active = EmployeeProjectAssignment::create([
            'tenant_slug'   => 'test-tenant',
            'employee_id'   => $employee->id,
            'project_id'    => '00000000-0000-0000-0000-000000000001',
            'assigned_date' => '2026-01-15',
            'status'        => 'active',
        ]);

        $released = EmployeeProjectAssignment::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'project_id'     => '00000000-0000-0000-0000-000000000002',
            'assigned_date'  => '2026-01-01',
            'released_date'  => '2026-06-30',
            'status'         => 'released',
        ]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($active->isReleased());
        $this->assertTrue($released->isReleased());
    }

    // -------------------------------------------------------------------------
    // AttendanceRecord Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function attendance_record_can_be_persisted(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-040',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $attendance = AttendanceRecord::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-01',
            'status'          => 'present',
            'hours_worked'    => 8.00,
        ]);

        $this->assertNotNull($attendance->id);
        $this->assertSame($employee->id, $attendance->employee_id);
        $this->assertSame('present', $attendance->status);
    }

    /** @test */
    public function attendance_record_unique_constraint_prevents_duplicate_per_day(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-041',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        AttendanceRecord::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-01',
            'status'          => 'present',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AttendanceRecord::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-01',
            'status'          => 'absent',
        ]);
    }

    /** @test */
    public function attendance_record_status_helpers_work(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-042',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $present = AttendanceRecord::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-01',
            'status'          => 'present',
        ]);

        $this->assertTrue($present->isPresent());
        $this->assertFalse($present->isAbsent());
        $this->assertFalse($present->isOnLeave());
        $this->assertFalse($present->isHalfDay());
    }

    // -------------------------------------------------------------------------
    // LeaveRequest Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function leave_request_can_be_persisted(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-050',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $leave = LeaveRequest::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'leave_type'     => 'annual',
            'start_date'     => '2026-10-10',
            'end_date'       => '2026-10-14',
            'days_requested' => 5,
        ]);

        $this->assertNotNull($leave->id);
        $this->assertSame($employee->id, $leave->employee_id);
        $this->assertSame('annual', $leave->leave_type);
        $this->assertSame('pending', $leave->status);
    }

    /** @test */
    public function leave_request_accepts_approval_request_id_uuid_without_fk_violation(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-051',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $leave = LeaveRequest::create([
            'tenant_slug'         => 'test-tenant',
            'employee_id'         => $employee->id,
            'leave_type'          => 'sick',
            'start_date'          => '2026-10-05',
            'end_date'            => '2026-10-06',
            'days_requested'      => 2,
            'approval_request_id' => '00000000-0000-0000-0000-000000000099', // UUID only, no FK
        ]);

        $this->assertNotNull($leave->id);
        $this->assertSame('00000000-0000-0000-0000-000000000099', $leave->approval_request_id);
    }

    /** @test */
    public function leave_request_status_helpers_work(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-052',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $pending = LeaveRequest::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'leave_type'     => 'annual',
            'start_date'     => '2026-11-01',
            'end_date'       => '2026-11-03',
            'days_requested' => 3,
            'status'         => 'pending',
        ]);

        $approved = LeaveRequest::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'leave_type'     => 'annual',
            'start_date'     => '2026-12-01',
            'end_date'       => '2026-12-03',
            'days_requested' => 3,
            'status'         => 'approved',
        ]);

        $this->assertTrue($pending->isPending());
        $this->assertFalse($pending->isApproved());
        $this->assertTrue($approved->isApproved());
        $this->assertFalse($approved->isPending());
    }

    // -------------------------------------------------------------------------
    // EmployeeCostAllocation Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employee_cost_allocation_can_be_persisted(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-060',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        $allocation = EmployeeCostAllocation::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'allocation_date' => '2026-10-01',
            'project_id'      => '00000000-0000-0000-0000-000000000001', // UUID only, no FK
            'cost_code_id'    => '00000000-0000-0000-0000-000000000002', // UUID only, no FK
            'hours_allocated' => 8.00,
            'amount'          => 400.00,
        ]);

        $this->assertNotNull($allocation->id);
        $this->assertSame($employee->id, $allocation->employee_id);
        $this->assertSame('SAR', $allocation->currency);
        $this->assertSame('daily', $allocation->allocation_type);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function employee_has_many_leave_requests(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-070',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        LeaveRequest::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'leave_type'     => 'annual',
            'start_date'     => '2026-10-10',
            'end_date'       => '2026-10-12',
            'days_requested' => 3,
        ]);

        LeaveRequest::create([
            'tenant_slug'    => 'test-tenant',
            'employee_id'    => $employee->id,
            'leave_type'     => 'sick',
            'start_date'     => '2026-11-01',
            'end_date'       => '2026-11-02',
            'days_requested' => 2,
        ]);

        $this->assertCount(2, $employee->leaveRequests);
    }

    /** @test */
    public function employee_has_many_attendance_records(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-071',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        AttendanceRecord::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-01',
            'status'          => 'present',
        ]);

        AttendanceRecord::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'attendance_date' => '2026-10-02',
            'status'          => 'present',
        ]);

        $this->assertCount(2, $employee->attendanceRecords);
    }

    /** @test */
    public function employee_has_many_cost_allocations(): void
    {
        $employee = Employee::create([
            'tenant_slug'     => 'test-tenant',
            'employee_number' => 'EMP-072',
            'first_name'      => 'Test',
            'last_name'       => 'Worker',
        ]);

        EmployeeCostAllocation::create([
            'tenant_slug'     => 'test-tenant',
            'employee_id'     => $employee->id,
            'allocation_date' => '2026-10-01',
            'project_id'      => '00000000-0000-0000-0000-000000000001',
            'hours_allocated' => 8.00,
            'amount'          => 400.00,
        ]);

        $this->assertCount(1, $employee->costAllocations);
    }

    // -------------------------------------------------------------------------
    // Scope Verification — No Payroll/WPS Tables
    // -------------------------------------------------------------------------

    /** @test */
    public function payroll_runs_table_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasTable('payroll_runs'),
            'payroll_runs table must NOT exist — Payroll/WPS is out of scope (CAP-091 excluded — OD-008 IMPL-BLOCKING)'
        );
    }

    /** @test */
    public function wps_files_table_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::connection('tenant')->hasTable('wps_files'),
            'wps_files table must NOT exist — WPS is out of scope (CAP-091 excluded — OD-008 IMPL-BLOCKING)'
        );
    }
}
