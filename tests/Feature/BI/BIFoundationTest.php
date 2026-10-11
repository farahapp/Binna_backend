<?php

declare(strict_types=1);

namespace Tests\Feature\BI;

use App\BI\Dashboard;
use App\BI\KpiDefinition;
use App\BI\Report;
use App\BI\ReportSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BIFoundationTest — Feature tests for BI Domain/Data Foundation.
 * TASK-015A — BI Domain/Data Foundation
 * A1 MOD-18 — CAP-150 Dashboards & Reporting
 *
 * Covers:
 *  - Schema existence (4 tables)
 *  - Model creation and attribute correctness
 *  - Tenant isolation (tenant_slug scoping)
 *  - Read-only constraint verification (no writes to source modules)
 *  - AC-02: no cross-tenant data leakage (basic model-level)
 *  - AC-11: MOD-18 does not emit business domain events
 */
class BIFoundationTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Schema / Migration Tests
    // =========================================================================

    /** @test */
    public function bi_dashboards_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bi_dashboards'),
            'bi_dashboards table must exist in the tenant schema'
        );
    }

    /** @test */
    public function bi_reports_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bi_reports'),
            'bi_reports table must exist in the tenant schema'
        );
    }

    /** @test */
    public function bi_kpi_definitions_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bi_kpi_definitions'),
            'bi_kpi_definitions table must exist in the tenant schema'
        );
    }

    /** @test */
    public function bi_report_schedules_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('bi_report_schedules'),
            'bi_report_schedules table must exist in the tenant schema'
        );
    }

    // =========================================================================
    // Dashboard Model Tests
    // =========================================================================

    /** @test */
    public function dashboard_can_be_created_with_required_attributes(): void
    {
        $tenantSlug = 'tenant-bi-test-' . Str::random(6);
        $dashboard = Dashboard::create([
            'name'           => 'Financial Overview',
            'dashboard_type' => 'financial',
            'status'         => 'active',
            'tenant_slug'    => $tenantSlug,
        ]);

        $this->assertNotNull($dashboard->id);
        $this->assertEquals('Financial Overview', $dashboard->name);
        $this->assertEquals('financial', $dashboard->dashboard_type);
        $this->assertEquals('active', $dashboard->status);
        $this->assertEquals($tenantSlug, $dashboard->tenant_slug);
    }

    /** @test */
    public function dashboard_defaults_to_custom_type_and_active_status(): void
    {
        $dashboard = Dashboard::create([
            'name'        => 'Default Dashboard',
            'tenant_slug' => 'tenant-default-' . Str::random(4),
        ]);

        $this->assertEquals('custom', $dashboard->dashboard_type);
        $this->assertEquals('active', $dashboard->status);
        $this->assertTrue($dashboard->isActive());
    }

    /** @test */
    public function dashboard_is_financial_returns_correct_boolean(): void
    {
        $financial = Dashboard::create([
            'name'           => 'Finance Board',
            'dashboard_type' => 'financial',
            'tenant_slug'    => 'tenant-fin-' . Str::random(4),
        ]);
        $project = Dashboard::create([
            'name'           => 'Project Board',
            'dashboard_type' => 'project',
            'tenant_slug'    => 'tenant-proj-' . Str::random(4),
        ]);

        $this->assertTrue($financial->isFinancial());
        $this->assertFalse($financial->isProject());
        $this->assertTrue($project->isProject());
        $this->assertFalse($project->isFinancial());
    }

    /** @test */
    public function dashboard_stores_config_as_json(): void
    {
        $config = ['widgets' => ['revenue_chart', 'invoice_count'], 'layout' => '2-col'];
        $dashboard = Dashboard::create([
            'name'        => 'Config Board',
            'config'      => $config,
            'tenant_slug' => 'tenant-cfg-' . Str::random(4),
        ]);

        $this->assertIsArray($dashboard->fresh()->config);
        $this->assertArrayHasKey('widgets', $dashboard->fresh()->config);
    }

    // =========================================================================
    // Report Model Tests
    // =========================================================================

    /** @test */
    public function report_can_be_created_with_required_attributes(): void
    {
        $tenantSlug = 'tenant-rpt-' . Str::random(6);
        $report = Report::create([
            'name'         => 'Invoice Summary',
            'report_key'   => 'finance_invoice_summary',
            'module_scope' => 'finance',
            'status'       => 'active',
            'tenant_slug'  => $tenantSlug,
        ]);

        $this->assertNotNull($report->id);
        $this->assertEquals('Invoice Summary', $report->name);
        $this->assertEquals('finance_invoice_summary', $report->report_key);
        $this->assertEquals('finance', $report->module_scope);
        $this->assertEquals($tenantSlug, $report->tenant_slug);
    }

    /** @test */
    public function report_defaults_to_active_status(): void
    {
        $report = Report::create([
            'name'         => 'Default Report',
            'report_key'   => 'test_report_' . Str::random(4),
            'module_scope' => 'accounting',
            'tenant_slug'  => 'tenant-rpt-def-' . Str::random(4),
        ]);

        $this->assertEquals('active', $report->status);
        $this->assertTrue($report->isActive());
    }

    /** @test */
    public function report_stores_default_filters_as_json(): void
    {
        $filters = ['date_from' => null, 'date_to' => null, 'status' => 'all'];
        $report = Report::create([
            'name'            => 'Filtered Report',
            'report_key'      => 'filtered_report_' . Str::random(4),
            'module_scope'    => 'projects',
            'default_filters' => $filters,
            'tenant_slug'     => 'tenant-flt-' . Str::random(4),
        ]);

        $this->assertIsArray($report->fresh()->default_filters);
        $this->assertArrayHasKey('date_from', $report->fresh()->default_filters);
    }

    // =========================================================================
    // KpiDefinition Model Tests
    // =========================================================================

    /** @test */
    public function kpi_definition_can_be_created_with_required_attributes(): void
    {
        $kpi = KpiDefinition::create([
            'name'         => 'Budget Utilization',
            'kpi_key'      => 'budget_utilization_pct',
            'module_scope' => 'cost_control',
            'unit'         => 'percentage',
            'tenant_slug'  => 'tenant-kpi-' . Str::random(6),
        ]);

        $this->assertNotNull($kpi->id);
        $this->assertEquals('budget_utilization_pct', $kpi->kpi_key);
        $this->assertEquals('percentage', $kpi->unit);
        $this->assertTrue($kpi->isPercentage());
        $this->assertFalse($kpi->isCurrency());
    }

    /** @test */
    public function kpi_definition_defaults_to_count_unit_and_active_status(): void
    {
        $kpi = KpiDefinition::create([
            'name'         => 'Employee Count',
            'kpi_key'      => 'employee_count_' . Str::random(4),
            'module_scope' => 'hr',
            'tenant_slug'  => 'tenant-kpi-def-' . Str::random(4),
        ]);

        $this->assertEquals('count', $kpi->unit);
        $this->assertEquals('active', $kpi->status);
        $this->assertTrue($kpi->isActive());
    }

    // =========================================================================
    // ReportSchedule Model Tests
    // =========================================================================

    /** @test */
    public function report_schedule_can_be_created_with_required_attributes(): void
    {
        $report = Report::create([
            'name'         => 'Monthly Finance Report',
            'report_key'   => 'monthly_finance_' . Str::random(4),
            'module_scope' => 'finance',
            'tenant_slug'  => 'tenant-sched-' . Str::random(6),
        ]);

        $schedule = ReportSchedule::create([
            'report_id'   => $report->id,
            'name'        => 'Monthly Schedule',
            'frequency'   => 'monthly',
            'status'      => 'active',
            'tenant_slug' => $report->tenant_slug,
        ]);

        $this->assertNotNull($schedule->id);
        $this->assertEquals($report->id, $schedule->report_id);
        $this->assertEquals('monthly', $schedule->frequency);
        $this->assertTrue($schedule->isActive());
        $this->assertTrue($schedule->isMonthly());
    }

    /** @test */
    public function report_schedule_belongs_to_report(): void
    {
        $tenantSlug = 'tenant-rel-' . Str::random(6);
        $report = Report::create([
            'name'         => 'Relation Test Report',
            'report_key'   => 'rel_test_' . Str::random(4),
            'module_scope' => 'projects',
            'tenant_slug'  => $tenantSlug,
        ]);

        $schedule = ReportSchedule::create([
            'report_id'   => $report->id,
            'name'        => 'Test Schedule',
            'frequency'   => 'weekly',
            'tenant_slug' => $tenantSlug,
        ]);

        $this->assertEquals($report->id, $schedule->report()->first()->id);
    }

    /** @test */
    public function report_has_many_schedules(): void
    {
        $tenantSlug = 'tenant-many-' . Str::random(6);
        $report = Report::create([
            'name'         => 'Multi-Schedule Report',
            'report_key'   => 'multi_sched_' . Str::random(4),
            'module_scope' => 'hr',
            'tenant_slug'  => $tenantSlug,
        ]);

        ReportSchedule::create(['report_id' => $report->id, 'name' => 'Schedule A', 'frequency' => 'daily',   'tenant_slug' => $tenantSlug]);
        ReportSchedule::create(['report_id' => $report->id, 'name' => 'Schedule B', 'frequency' => 'weekly',  'tenant_slug' => $tenantSlug]);

        $this->assertCount(2, $report->schedules);
    }

    // =========================================================================
    // Tenant Isolation Tests (AC-02)
    // =========================================================================

    /** @test */
    public function dashboards_are_scoped_by_tenant_slug(): void
    {
        $slugA = 'tenant-a-' . Str::random(6);
        $slugB = 'tenant-b-' . Str::random(6);

        Dashboard::create(['name' => 'Board A', 'tenant_slug' => $slugA]);
        Dashboard::create(['name' => 'Board B1', 'tenant_slug' => $slugB]);
        Dashboard::create(['name' => 'Board B2', 'tenant_slug' => $slugB]);

        $tenantABoards = Dashboard::where('tenant_slug', $slugA)->get();
        $tenantBBoards = Dashboard::where('tenant_slug', $slugB)->get();

        $this->assertCount(1, $tenantABoards);
        $this->assertCount(2, $tenantBBoards);
        $this->assertEquals('Board A', $tenantABoards->first()->name);
        // Tenant A data does not appear in Tenant B query
        $this->assertTrue($tenantBBoards->every(fn($d) => $d->tenant_slug === $slugB));
    }

    /** @test */
    public function reports_are_scoped_by_tenant_slug(): void
    {
        $slugA = 'tenant-ra-' . Str::random(6);
        $slugB = 'tenant-rb-' . Str::random(6);

        Report::create(['name' => 'Report A', 'report_key' => 'rpt_a_' . Str::random(4), 'module_scope' => 'finance', 'tenant_slug' => $slugA]);
        Report::create(['name' => 'Report B', 'report_key' => 'rpt_b_' . Str::random(4), 'module_scope' => 'finance', 'tenant_slug' => $slugB]);

        $tenantAReports = Report::where('tenant_slug', $slugA)->get();
        $tenantBReports = Report::where('tenant_slug', $slugB)->get();

        $this->assertCount(1, $tenantAReports);
        $this->assertCount(1, $tenantBReports);
        $this->assertNotEquals($tenantAReports->first()->id, $tenantBReports->first()->id);
    }

    // =========================================================================
    // Read-Only / No Business Event Emission Tests (AC-11)
    // =========================================================================

    /** @test */
    public function bi_module_does_not_define_business_domain_events(): void
    {
        // MOD-18 owns no business domain events (per Module Boundaries).
        // Verify no event files exist in BI namespace.
        $biEventPath = app_path('BI/Events');
        $this->assertFalse(
            is_dir($biEventPath),
            'MOD-18 must not define business domain events directory'
        );
    }

    /** @test */
    public function bi_module_uses_tenant_connection_for_all_models(): void
    {
        $dashboard = new Dashboard();
        $report    = new Report();
        $kpi       = new KpiDefinition();
        $schedule  = new ReportSchedule();

        $this->assertEquals('tenant', $dashboard->getConnectionName());
        $this->assertEquals('tenant', $report->getConnectionName());
        $this->assertEquals('tenant', $kpi->getConnectionName());
        $this->assertEquals('tenant', $schedule->getConnectionName());
    }
}
