<?php

declare(strict_types=1);

namespace Tests\Unit\Projects;

use App\Projects\DailyReport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DailyReport model — TASK-005A Project Domain/Data Foundation.
 *
 * Verifies model configuration without database connection.
 */
class DailyReportModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Tenant Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function daily_report_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new DailyReport())->getConnectionName(),
            'DailyReport must use the per-tenant connection (OD-001)'
        );
    }

    // -------------------------------------------------------------------------
    // Table Name Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function daily_report_uses_correct_table(): void
    {
        $this->assertSame('daily_reports', (new DailyReport())->getTable());
    }

    // -------------------------------------------------------------------------
    // Fillable Fields Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function daily_report_has_required_fillable_fields(): void
    {
        $fillable = (new DailyReport())->getFillable();
        foreach ([
            'project_id',
            'tenant_slug',
            'report_date',
            'submitted_by',
            'weather',
            'work_done',
            'issues',
            'progress_percentage',
        ] as $field) {
            $this->assertContains($field, $fillable, "DailyReport must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function daily_report_has_tenant_slug_in_fillable(): void
    {
        $this->assertContains(
            'tenant_slug',
            (new DailyReport())->getFillable(),
            'DailyReport must have tenant_slug in fillable (OD-001 cross-schema traceability)'
        );
    }

    // -------------------------------------------------------------------------
    // Casts Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function daily_report_casts_report_date_to_date(): void
    {
        $casts = (new DailyReport())->getCasts();
        $this->assertArrayHasKey('report_date', $casts);
        $this->assertSame('date', $casts['report_date']);
    }

    /** @test */
    public function daily_report_casts_progress_percentage_to_integer(): void
    {
        $casts = (new DailyReport())->getCasts();
        $this->assertArrayHasKey('progress_percentage', $casts);
        $this->assertSame('integer', $casts['progress_percentage']);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests (structural — no DB)
    // -------------------------------------------------------------------------

    /** @test */
    public function daily_report_belongs_to_project(): void
    {
        $this->assertTrue(method_exists(DailyReport::class, 'project'));
    }
}
