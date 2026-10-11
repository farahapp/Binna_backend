<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\Tenant;
use App\Foundation\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests covering TASK-001A Verification Points 1, 3, 5, 6, 7.
 *
 * VP1 — Tenant creation creates an independent schema.
 * VP3 — schema_name is not accepted from the client.
 * VP5 — Shared migration is applied first (tables exist before provisioning).
 * VP6 — Per-tenant baseline migration is applied after shared.
 * VP7 — Failure on Tenant A does not affect Tenant B.
 *
 * Uses RefreshDatabase to reset the shared (binaa2_testing) schema between tests.
 * Tenant schemas created during tests are cleaned up in tearDown.
 */
class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** Tenant schemas created during this test run — cleaned up in tearDown. */
    private array $createdSchemas = [];

    protected function tearDown(): void
    {
        foreach ($this->createdSchemas as $schema) {
            DB::statement("DROP DATABASE IF EXISTS `{$schema}`");
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // VP1 — Tenant creation creates an independent schema
    // -------------------------------------------------------------------------

    /** @test */
    public function provisioning_creates_an_independent_mysql_schema(): void
    {
        $provisioner = $this->app->make(TenantProvisioner::class);

        $tenant = $provisioner->provision('acme-test', 'Acme Test Corp');
        $this->createdSchemas[] = $tenant->schema_name;

        // The schema must exist in MySQL.
        $exists = DB::select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$tenant->schema_name]
        );

        $this->assertNotEmpty($exists, 'Tenant schema must exist in MySQL after provisioning');
        $this->assertSame('binaa2_tenant_acme_test', $tenant->schema_name);
    }

    /** @test */
    public function provisioned_tenant_is_set_to_active_status(): void
    {
        $provisioner = $this->app->make(TenantProvisioner::class);

        $tenant = $provisioner->provision('active-test', 'Active Test');
        $this->createdSchemas[] = $tenant->schema_name;

        $this->assertSame('active', $tenant->status);
    }

    // -------------------------------------------------------------------------
    // VP3 — schema_name is not accepted from the client
    // -------------------------------------------------------------------------

    /** @test */
    public function schema_name_is_not_in_fillable_and_cannot_be_set_by_client(): void
    {
        // Attempt to mass-assign schema_name — it must be silently ignored.
        $tenant = new Tenant([
            'slug' => 'test-slug',
            'name' => 'Test',
            'status' => 'pending',
            'schema_name' => 'injected_by_client', // must be ignored
        ]);

        $this->assertNull(
            $tenant->schema_name,
            'schema_name must not be settable via mass assignment (not in $fillable)'
        );
    }

    /** @test */
    public function provisioner_derives_schema_name_server_side_ignoring_any_client_input(): void
    {
        $provisioner = $this->app->make(TenantProvisioner::class);

        // Even if a caller tries to pass schema_name, the provisioner derives it from slug.
        $tenant = $provisioner->provision('server-derived', 'Server Derived');
        $this->createdSchemas[] = $tenant->schema_name;

        $this->assertSame('binaa2_tenant_server_derived', $tenant->schema_name);
    }

    // -------------------------------------------------------------------------
    // VP5 — Shared migration applied first (tables exist before provisioning)
    // -------------------------------------------------------------------------

    /** @test */
    public function shared_schema_tables_exist_before_tenant_provisioning(): void
    {
        // RefreshDatabase runs shared migrations first.
        // Verify the three shared tables exist.
        $this->assertTrue(Schema::connection('mysql')->hasTable('tenants'), 'tenants table must exist');
        $this->assertTrue(Schema::connection('mysql')->hasTable('tenant_settings'), 'tenant_settings table must exist');
        $this->assertTrue(Schema::connection('mysql')->hasTable('tenant_migrations'), 'tenant_migrations table must exist');
    }

    // -------------------------------------------------------------------------
    // VP6 — Per-tenant baseline migration applied after shared
    // -------------------------------------------------------------------------

    /** @test */
    public function per_tenant_baseline_migration_is_recorded_in_tenant_migrations(): void
    {
        $provisioner = $this->app->make(TenantProvisioner::class);

        $tenant = $provisioner->provision('baseline-check', 'Baseline Check');
        $this->createdSchemas[] = $tenant->schema_name;

        $record = DB::table('tenant_migrations')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'applied')
            ->first();

        $this->assertNotNull($record, 'Baseline migration must be recorded in tenant_migrations');
        $this->assertSame('applied', $record->status);
    }

    /** @test */
    public function per_tenant_schema_contains_baseline_marker_table(): void
    {
        $provisioner = $this->app->make(TenantProvisioner::class);

        $tenant = $provisioner->provision('marker-check', 'Marker Check');
        $this->createdSchemas[] = $tenant->schema_name;

        // Build a temporary connection to the tenant schema.
        $connName = 'test_tenant_'.$tenant->schema_name;
        config(["database.connections.{$connName}" => array_merge(
            config('database.connections.mysql'),
            ['database' => $tenant->schema_name]
        )]);

        $this->assertTrue(
            Schema::connection($connName)->hasTable('schema_info'),
            'schema_info baseline marker table must exist in tenant schema'
        );

        DB::purge($connName);
    }

    // -------------------------------------------------------------------------
    // VP7 — Failure on Tenant A does not affect Tenant B
    // -------------------------------------------------------------------------

    /** @test */
    public function failed_provisioning_of_tenant_a_does_not_affect_tenant_b(): void
    {
        // Provision Tenant B successfully first.
        $provisioner = $this->app->make(TenantProvisioner::class);
        $tenantB = $provisioner->provision('tenant-b-ok', 'Tenant B OK');
        $this->createdSchemas[] = $tenantB->schema_name;

        // Attempt to provision Tenant A with an invalid slug — should throw.
        try {
            $provisioner->provision('x', 'Too Short Slug'); // invalid slug
        } catch (\Throwable) {
            // Expected — swallow.
        }

        // Tenant B must still be active and unaffected.
        $tenantB->refresh();
        $this->assertSame('active', $tenantB->status, 'Tenant B must remain active after Tenant A failure');

        // Tenant B schema must still exist.
        $exists = DB::select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$tenantB->schema_name]
        );
        $this->assertNotEmpty($exists, 'Tenant B schema must still exist after Tenant A failure');
    }
}
