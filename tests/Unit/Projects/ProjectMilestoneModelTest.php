<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use App\Projects\ProjectMilestone;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProjectMilestone model — TASK-005A Project Domain/Data Foundation.
 */
class ProjectMilestoneModelTest extends TestCase
{
    public function test_project_milestone_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new ProjectMilestone())->getConnectionName());
    }

    public function test_project_milestone_uses_correct_table(): void
    {
        $this->assertSame('project_milestones', (new ProjectMilestone())->getTable());
    }

    public function test_project_milestone_has_required_fillable_fields(): void
    {
        $fillable = (new ProjectMilestone())->getFillable();
        foreach (['project_id', 'tenant_slug', 'name', 'due_date', 'status'] as $field) {
            $this->assertContains($field, $fillable, "ProjectMilestone must have '{$field}' in fillable");
        }
    }

    public function test_project_milestone_has_tenant_slug_in_fillable(): void
    {
        $this->assertContains('tenant_slug', (new ProjectMilestone())->getFillable());
    }

    public function test_project_milestone_casts_dates_correctly(): void
    {
        $casts = (new ProjectMilestone())->getCasts();
        $this->assertArrayHasKey('due_date', $casts);
        $this->assertSame('date', $casts['due_date']);
    }

    public function test_project_milestone_status_helpers_work(): void
    {
        $m = new ProjectMilestone();

        $m->status = 'pending';
        $this->assertTrue($m->isPending());
        $this->assertFalse($m->isReached());

        $m->status = 'reached';
        $this->assertTrue($m->isReached());
        $this->assertFalse($m->isPending());

        $m->status = 'missed';
        $this->assertTrue($m->isMissed());
    }

    public function test_project_milestone_has_project_relationship(): void
    {
        $this->assertTrue(method_exists(ProjectMilestone::class, 'project'));
    }
}
