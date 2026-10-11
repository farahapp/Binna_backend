<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use App\Projects\DailyReport;
use App\Projects\Project;
use App\Projects\ProjectActivity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Project model — TASK-005A Project Domain/Data Foundation.
 *
 * Verifies model configuration without database connection.
 */
class ProjectModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Tenant Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new Project())->getConnectionName(),
            'Project must use the per-tenant connection (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Table Name Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_uses_correct_table(): void
    {
        $this->assertSame('projects', (new Project())->getTable());
    }

    // -------------------------------------------------------------------------
    // Fillable Fields Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_has_required_fillable_fields(): void
    {
        $fillable = (new Project())->getFillable();
        foreach ([
            'tenant_slug',
            'name',
            'description',
            'status',
            'client_id',
            'contract_id',
            'start_date',
            'end_date',
            'manager_id',
        ] as $field) {
            $this->assertContains($field, $fillable, "Project must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function project_has_tenant_slug_in_fillable(): void
    {
        $this->assertContains(
            'tenant_slug',
            (new Project())->getFillable(),
            'Project must have tenant_slug in fillable (OD-001 cross-schema traceability)'
        );
    }

    // -------------------------------------------------------------------------
    // Casts Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_casts_dates_correctly(): void
    {
        $casts = (new Project())->getCasts();
        $this->assertArrayHasKey('start_date', $casts);
        $this->assertArrayHasKey('end_date', $casts);
        $this->assertSame('date', $casts['start_date']);
        $this->assertSame('date', $casts['end_date']);
    }

    // -------------------------------------------------------------------------
    // Status Helper Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_status_helpers_work_correctly(): void
    {
        $project = new Project();

        $project->status = 'draft';
        $this->assertTrue($project->isDraft());
        $this->assertFalse($project->isActive());

        $project->status = 'active';
        $this->assertTrue($project->isActive());
        $this->assertFalse($project->isDraft());

        $project->status = 'on_hold';
        $this->assertTrue($project->isOnHold());

        $project->status = 'closed';
        $this->assertTrue($project->isClosed());
    }

    // -------------------------------------------------------------------------
    // Relationship Tests (structural — no DB)
    // -------------------------------------------------------------------------

    /** @test */
    public function project_has_activities_relationship(): void
    {
        $this->assertTrue(method_exists(Project::class, 'activities'));
    }

    /** @test */
    public function project_has_daily_reports_relationship(): void
    {
        $this->assertTrue(method_exists(Project::class, 'dailyReports'));
    }
}
