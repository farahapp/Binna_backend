<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use App\Projects\ProjectActivity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProjectActivity model — TASK-005A Project Domain/Data Foundation.
 *
 * Verifies model configuration without database connection.
 */
class ProjectActivityModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Tenant Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_activity_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new ProjectActivity())->getConnectionName(),
            'ProjectActivity must use the per-tenant connection (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Table Name Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_activity_uses_correct_table(): void
    {
        $this->assertSame('project_activities', (new ProjectActivity())->getTable());
    }

    // -------------------------------------------------------------------------
    // Fillable Fields Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_activity_has_required_fillable_fields(): void
    {
        $fillable = (new ProjectActivity())->getFillable();
        foreach ([
            'project_id',
            'tenant_slug',
            'name',
            'description',
            'status',
            'planned_start',
            'planned_end',
            'actual_start',
            'actual_end',
            'progress_percentage',
            'parent_activity_id',
        ] as $field) {
            $this->assertContains($field, $fillable, "ProjectActivity must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function project_activity_has_tenant_slug_in_fillable(): void
    {
        $this->assertContains(
            'tenant_slug',
            (new ProjectActivity())->getFillable(),
            'ProjectActivity must have tenant_slug in fillable (OD-001 cross-schema traceability)'
        );
    }

    // -------------------------------------------------------------------------
    // Casts Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_activity_casts_dates_correctly(): void
    {
        $casts = (new ProjectActivity())->getCasts();
        foreach (['planned_start', 'planned_end', 'actual_start', 'actual_end'] as $field) {
            $this->assertArrayHasKey($field, $casts, "ProjectActivity must cast '{$field}'");
            $this->assertSame('date', $casts[$field]);
        }
    }

    /** @test */
    public function project_activity_casts_progress_percentage_to_integer(): void
    {
        $casts = (new ProjectActivity())->getCasts();
        $this->assertArrayHasKey('progress_percentage', $casts);
        $this->assertSame('integer', $casts['progress_percentage']);
    }

    // -------------------------------------------------------------------------
    // Status Helper Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function project_activity_status_helpers_work_correctly(): void
    {
        $activity = new ProjectActivity();

        $activity->status = 'pending';
        $this->assertTrue($activity->isPending());
        $this->assertFalse($activity->isInProgress());

        $activity->status = 'in_progress';
        $this->assertTrue($activity->isInProgress());
        $this->assertFalse($activity->isPending());

        $activity->status = 'completed';
        $this->assertTrue($activity->isCompleted());

        $activity->status = 'cancelled';
        $this->assertTrue($activity->isCancelled());
    }

    // -------------------------------------------------------------------------
    // Relationship Tests (structural — no DB)
    // -------------------------------------------------------------------------

    /** @test */
    public function project_activity_belongs_to_project(): void
    {
        $this->assertTrue(method_exists(ProjectActivity::class, 'project'));
    }

    /** @test */
    public function project_activity_has_children_relationship(): void
    {
        $this->assertTrue(method_exists(ProjectActivity::class, 'children'));
    }

    /** @test */
    public function project_activity_belongs_to_parent(): void
    {
        $this->assertTrue(method_exists(ProjectActivity::class, 'parent'));
    }
}
