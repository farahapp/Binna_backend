<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use App\Projects\ProjectTeam;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProjectTeam model — TASK-005A Project Domain/Data Foundation.
 */
class ProjectTeamModelTest extends TestCase
{
    public function test_project_team_uses_tenant_connection(): void
    {
        $this->assertSame('tenant', (new ProjectTeam())->getConnectionName());
    }

    public function test_project_team_uses_correct_table(): void
    {
        $this->assertSame('project_team', (new ProjectTeam())->getTable());
    }

    public function test_project_team_has_required_fillable_fields(): void
    {
        $fillable = (new ProjectTeam())->getFillable();
        foreach (['project_id', 'tenant_slug', 'user_id', 'role'] as $field) {
            $this->assertContains($field, $fillable, "ProjectTeam must have '{$field}' in fillable");
        }
    }

    public function test_project_team_has_tenant_slug_in_fillable(): void
    {
        $this->assertContains('tenant_slug', (new ProjectTeam())->getFillable());
    }

    public function test_project_team_has_project_relationship(): void
    {
        $this->assertTrue(method_exists(ProjectTeam::class, 'project'));
    }
}
