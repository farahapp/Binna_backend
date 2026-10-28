<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\DomainMigrator;
use App\Foundation\Tenant;
use App\Foundation\TenantMigration;
use App\Foundation\TenantProvisioner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for the Domain Migration Gap fix (PROV-DOM-001).
 *
 * Verification Points covered:
 *
 *   VP-DOM-1  — Provisioned tenant schema contains all required domain tables.
 *   VP-DOM-2  — Domain migrations are recorded in tenant_migrations (Batch 3).
 *   VP-DOM-3  — Tenant is NOT activated if a domain migration fails.
 *   VP-DOM-4  — Domain migrations are idempotent (safe to re-run).
 *   VP-DOM-5  — Two tenants have independent domain schemas (CRM isolation).
 *   VP-DOM-6  — DomainMigrator is injected into TenantProvisioner (DI contract).
 *   VP-DOM-7  — trial-alpha and trial-beta schemas are unaffected by provisioning.
 *   VP-DOM-8  — migrations table exists and records all three batches.
 *
 * Uses DatabaseTransactions to wrap shared-schema writes.
 * Tenant schemas created during tests are dropped in tearDown.
 *
 * Runs against the MySQL 3308 provisioning instance (same as TenantIamProvisioningTest).
 * Credentials are read from .env.testing.prov (gitignored).
 *
 * PROV-DOM-001 — Domain Migration Gap fix.
 */
class TenantDomainProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    /** Tenant schemas created during this test run — cleaned up in tearDown. */
    private array $createdSchemas = [];

    /** Original env vars saved before override — restored in tearDown. */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        // Override DB env vars to point at the 3308 provisioning instance,
        // mirroring TenantIamProvisioningTest::setUp() exactly.
        $envFile = dirname(__DIR__, 3) . '/.env.testing.prov';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $env   = [];
            foreach ($lines as $line) {
                if (str_contains($line, '=')) {
                    [$key, $val] = explode('=', $line, 2);
                    $env[trim($key)] = trim($val);
                }
            }

            $map = [
                'DB_HOST'            => $env['PROV_DB_HOST']      ?? '127.0.0.1',
                'DB_PORT'            => $env['PROV_DB_PORT']      ?? '3308',
                'DB_DATABASE'        => $env['PROV_DB_DATABASE']  ?? 'binaa2_testing',
                'DB_USERNAME'        => $env['PROV_DB_USERNAME']  ?? 'phpunit_prov_3308',
                'DB_PASSWORD'        => $env['PROV_DB_PASSWORD']  ?? '',
                'TENANT_DB_HOST'     => $env['PROV_DB_HOST']      ?? '127.0.0.1',
                'TENANT_DB_PORT'     => $env['PROV_DB_PORT']      ?? '3308',
                'TENANT_DB_DATABASE' => $env['PROV_DB_DATABASE']  ?? 'binaa2_testing',
                'TENANT_DB_USERNAME' => $env['PROV_DB_USERNAME']  ?? 'phpunit_prov_3308',
                'TENANT_DB_PASSWORD' => $env['PROV_DB_PASSWORD']  ?? '',
            ];

            foreach ($map as $key => $val) {
                $this->originalEnv[$key] = getenv($key);
                putenv("{$key}={$val}");
                $_ENV[$key]    = $val;
                $_SERVER[$key] = $val;
            }
        }

        parent::setUp();

        // Ensure shared schema tables exist on the 3308 instance.
        $hasMigrations = DB::connection('mysql')->getSchemaBuilder()->hasTable('migrations');
        if (! $hasMigrations) {
            $this->artisan('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        // Explicitly delete tenant rows and migration records written outside
        // the DatabaseTransactions scope (DDL causes implicit commits in MySQL).
        if (! empty($this->createdSchemas)) {
            $tenantIds = Tenant::whereIn('schema_name', $this->createdSchemas)->pluck('id');
            if ($tenantIds->isNotEmpty()) {
                TenantMigration::whereIn('tenant_id', $tenantIds)->delete();
                Tenant::whereIn('schema_name', $this->createdSchemas)->delete();
            }
        }

        // Drop the tenant schemas created during this test run.
        foreach ($this->createdSchemas as $schema) {
            try {
                DB::connection('mysql_provisioning')->statement("DROP DATABASE IF EXISTS `{$schema}`");
            } catch (\Throwable) {
                // Ignore — schema may not have been created if provisioning failed early.
            }
        }

        parent::tearDown();

        // Restore original env vars so other test classes are unaffected.
        foreach ($this->originalEnv as $key => $val) {
            if ($val === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$val}");
                $_ENV[$key]    = $val;
                $_SERVER[$key] = $val;
            }
        }
        $this->originalEnv = [];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a temporary named connection pointing at the given tenant schema
     * via the provisioning connection credentials.
     * Returns the connection name. Caller is responsible for DB::purge().
     */
    private function tenantConnection(string $schemaName): string
    {
        $connName = 'test_dom_' . $schemaName;
        config(["database.connections.{$connName}" => array_merge(
            config('database.connections.mysql_provisioning'),
            ['database' => $schemaName],
        )]);
        return $connName;
    }

    /**
     * Provision a fresh trial tenant and register its schema for cleanup.
     */
    private function provisionTrial(string $slug, string $name): Tenant
    {
        $provisioner = $this->app->make(TenantProvisioner::class);
        $tenant = $provisioner->provision($slug, $name);
        $this->createdSchemas[] = $tenant->schema_name;
        return $tenant;
    }

    // ─── VP-DOM-1 — All required domain tables exist after provisioning ───────

    /** @test */
    public function provisioned_tenant_schema_contains_crm_tables(): void
    {
        $tenant = $this->provisionTrial('dom-crm-check', 'Domain CRM Check');
        $conn   = $this->tenantConnection($tenant->schema_name);
        $schema = Schema::connection($conn);

        $crmTables = ['clients', 'contacts', 'leads', 'opportunities', 'crm_activities'];

        foreach ($crmTables as $table) {
            $this->assertTrue(
                $schema->hasTable($table),
                "CRM table [{$table}] must exist in tenant schema [{$tenant->schema_name}]"
            );
        }

        DB::purge($conn);
    }

    /** @test */
    public function provisioned_tenant_schema_contains_projects_tables(): void
    {
        $tenant = $this->provisionTrial('dom-proj-check', 'Domain Projects Check');
        $conn   = $this->tenantConnection($tenant->schema_name);
        $schema = Schema::connection($conn);

        $projectsTables = [
            'projects',
            'project_activities',
            'daily_reports',
            'project_milestones',
            'project_team',
            'project_parties',
        ];

        foreach ($projectsTables as $table) {
            $this->assertTrue(
                $schema->hasTable($table),
                "Projects table [{$table}] must exist in tenant schema [{$tenant->schema_name}]"
            );
        }

        DB::purge($conn);
    }

    /** @test */
    public function provisioned_tenant_schema_contains_finance_tables(): void
    {
        $tenant = $this->provisionTrial('dom-fin-check', 'Domain Finance Check');
        $conn   = $this->tenantConnection($tenant->schema_name);
        $schema = Schema::connection($conn);

        $financeTables = [
            'invoices',
            'invoice_items',
            'payments',
            'receivables',
            'collections',
            'bank_accounts',
            'cash_accounts',
        ];

        foreach ($financeTables as $table) {
            $this->assertTrue(
                $schema->hasTable($table),
                "Finance table [{$table}] must exist in tenant schema [{$tenant->schema_name}]"
            );
        }

        DB::purge($conn);
    }

    /** @test */
    public function provisioned_tenant_schema_contains_hr_tables(): void
    {
        $tenant = $this->provisionTrial('dom-hr-check', 'Domain HR Check');
        $conn   = $this->tenantConnection($tenant->schema_name);
        $schema = Schema::connection($conn);

        $hrTables = [
            'employees',
            'employment_records',
            'attendance_records',
            'leave_requests',
        ];

        foreach ($hrTables as $table) {
            $this->assertTrue(
                $schema->hasTable($table),
                "HR table [{$table}] must exist in tenant schema [{$tenant->schema_name}]"
            );
        }

        DB::purge($conn);
    }

    /** @test */
    public function provisioned_tenant_schema_contains_all_required_domain_tables(): void
    {
        $tenant = $this->provisionTrial('dom-full-check', 'Domain Full Check');
        $conn   = $this->tenantConnection($tenant->schema_name);
        $schema = Schema::connection($conn);

        // Comprehensive list covering all 17 domain directories.
        $requiredTables = [
            // Batch 1 — baseline
            'schema_info',
            // Batch 2 — IAM
            'users', 'roles', 'permissions', 'model_has_roles',
            'role_has_permissions', 'personal_access_tokens',
            // Batch 3 — audit
            'audit_entries',
            // Batch 3 — approval
            'approval_requests',
            // Batch 3 — notification
            'notifications', 'notification_preferences', 'notification_history',
            // Batch 3 — document
            'document_categories', 'documents', 'document_versions',
            // Batch 3 — crm
            'clients', 'contacts', 'leads', 'opportunities', 'crm_activities',
            // Batch 3 — commercial
            'tenders', 'boqs', 'contracts',
            // Batch 3 — projects
            'projects', 'project_activities', 'project_milestones',
            // Batch 3 — cost_control
            'cost_codes', 'budgets', 'actual_costs',
            // Batch 3 — hr
            'employees', 'attendance_records',
            // Batch 3 — equipment
            'assets', 'equipment_allocations',
            // Batch 3 — inventory
            'inventory_items', 'stock_levels',
            // Batch 3 — procurement
            'tenant_suppliers', 'purchase_requests', 'purchase_orders',
            // Batch 3 — finance
            'invoices', 'payments', 'collections',
            // Batch 3 — accounting
            'accounts', 'journal_entries',
            // Batch 3 — subcontracts
            'subcontract_agreements',
            // Batch 3 — bi
            'bi_dashboards', 'bi_reports',
            // NOTE: network_profiles and network_tenders live in the SHARED (mysql) schema,
            // not in per-tenant schemas (NETWORK-ARCH-001 / OD-001). They are intentionally
            // excluded from this per-tenant table check.
        ];

        $missing = [];
        foreach ($requiredTables as $table) {
            if (! $schema->hasTable($table)) {
                $missing[] = $table;
            }
        }

        $this->assertEmpty(
            $missing,
            'The following required tables are missing from tenant schema '
            . "[{$tenant->schema_name}]: " . implode(', ', $missing)
        );

        DB::purge($conn);
    }

    // ─── VP-DOM-2 — Domain migrations recorded in tenant_migrations ───────────

    /** @test */
    public function domain_migrations_are_recorded_in_tenant_migrations_as_batch_3(): void
    {
        $tenant = $this->provisionTrial('dom-record-check', 'Domain Record Check');

        $batch3Count = DB::table('tenant_migrations')
            ->where('tenant_id', $tenant->id)
            ->where('batch', 3)
            ->where('status', 'applied')
            ->count();

        // There are 16 per-tenant domain directories (network excluded — shared schema).
        // We assert at least 25 records to cover the minimum expected domain migrations.
        $this->assertGreaterThanOrEqual(
            25,
            $batch3Count,
            "At least 25 Batch 3 domain migration records must be recorded in tenant_migrations, got {$batch3Count}"
        );
    }

    /** @test */
    public function tenant_migrations_contains_all_three_batches(): void
    {
        $tenant = $this->provisionTrial('dom-batches-check', 'Domain Batches Check');

        foreach ([1, 2, 3] as $batch) {
            $count = DB::table('tenant_migrations')
                ->where('tenant_id', $tenant->id)
                ->where('batch', $batch)
                ->where('status', 'applied')
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $count,
                "At least 1 migration record must exist for Batch {$batch} in tenant_migrations"
            );
        }
    }

    /** @test */
    public function crm_domain_migrations_are_individually_recorded(): void
    {
        $tenant = $this->provisionTrial('dom-crm-record', 'Domain CRM Record');

        // CRM has 5 migration files — each must be recorded.
        $crmRecords = DB::table('tenant_migrations')
            ->where('tenant_id', $tenant->id)
            ->where('batch', 3)
            ->where('status', 'applied')
            ->where('migration', 'like', 'crm/%')
            ->count();

        $this->assertSame(
            5,
            $crmRecords,
            'All 5 CRM migration files must be individually recorded in tenant_migrations'
        );
    }

    // ─── VP-DOM-3 — Tenant NOT activated if domain migration fails ────────────

    /** @test */
    public function tenant_remains_pending_if_domain_migration_fails(): void
    {
        // Inject a failing DomainMigrator stub.
        $this->app->bind(DomainMigrator::class, function () {
            return new class extends DomainMigrator {
                public function run(Tenant $tenant): void
                {
                    throw new \RuntimeException('Simulated domain migration failure');
                }
            };
        });

        $provisioner = $this->app->make(TenantProvisioner::class);

        $slug       = 'dom-fail-test';
        $schemaName = Tenant::deriveSchemaName($slug);
        $this->createdSchemas[] = $schemaName;

        try {
            $provisioner->provision($slug, 'Domain Fail Test');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated domain migration failure', $e->getMessage());
        }

        // Tenant must remain in 'pending' — never activated.
        $tenant = Tenant::where('slug', $slug)->first();
        $this->assertNotNull($tenant, 'Tenant record must exist (pending)');
        $this->assertSame(
            'pending',
            $tenant->status,
            'Tenant must remain pending when domain migration fails'
        );
    }

    // ─── VP-DOM-4 — Domain migrations are idempotent ─────────────────────────

    /** @test */
    public function domain_migrator_is_idempotent_and_does_not_throw_on_rerun(): void
    {
        $tenant = $this->provisionTrial('dom-idempotent', 'Domain Idempotent');

        // Re-run DomainMigrator on the already-provisioned schema — must not throw.
        $domainMigrator = $this->app->make(DomainMigrator::class);

        try {
            $domainMigrator->run($tenant);
        } catch (\Throwable $e) {
            $this->fail(
                'DomainMigrator must be idempotent — second run threw: ' . $e->getMessage()
            );
        }

        $this->assertTrue(true, 'DomainMigrator re-run completed without exception');
    }

    // ─── VP-DOM-5 — Two tenants have independent domain schemas ───────────────

    /** @test */
    public function two_tenants_have_independent_crm_schemas(): void
    {
        $tenantA = $this->provisionTrial('dom-iso-alpha', 'Domain Iso Alpha');
        $tenantB = $this->provisionTrial('dom-iso-beta', 'Domain Iso Beta');

        $this->assertNotSame(
            $tenantA->schema_name,
            $tenantB->schema_name,
            'Two tenants must have different schema names'
        );

        $connA = $this->tenantConnection($tenantA->schema_name);
        $connB = $this->tenantConnection($tenantB->schema_name);

        // Both schemas must have the clients table.
        $this->assertTrue(Schema::connection($connA)->hasTable('clients'), 'Tenant A must have clients table');
        $this->assertTrue(Schema::connection($connB)->hasTable('clients'), 'Tenant B must have clients table');

        // Insert a client into Tenant A — must NOT appear in Tenant B.
        $clientId = \Illuminate\Support\Str::uuid()->toString();
        DB::connection($connA)->table('clients')->insert([
            'id'          => $clientId,
            'type'        => 'company',
            'name'        => 'Alpha Corp',
            'status'      => 'active',
            'tenant_slug' => 'dom-iso-alpha',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $foundInB = DB::connection($connB)->table('clients')
            ->where('id', $clientId)
            ->exists();

        $this->assertFalse(
            $foundInB,
            'Client inserted in Tenant A schema must NOT be visible in Tenant B schema (CRM isolation)'
        );

        DB::purge($connA);
        DB::purge($connB);
    }

    // ─── VP-DOM-6 — DomainMigrator is injected into TenantProvisioner ─────────

    /** @test */
    public function tenant_provisioner_receives_domain_migrator_via_dependency_injection(): void
    {
        $provisioner = $this->app->make(TenantProvisioner::class);

        $reflection  = new \ReflectionClass($provisioner);
        $constructor = $reflection->getConstructor();
        $params      = $constructor->getParameters();

        $paramTypes = array_map(
            fn ($p) => $p->getType()?->getName(),
            $params
        );

        $this->assertContains(
            DomainMigrator::class,
            $paramTypes,
            'TenantProvisioner must declare DomainMigrator as a constructor dependency'
        );
    }

    // ─── VP-DOM-7 — trial-alpha and trial-beta are unaffected ─────────────────

    /** @test */
    public function trial_alpha_schema_is_unaffected_by_new_provisioning(): void
    {
        // Verify trial-alpha tenant record still exists and is active.
        $trialAlpha = Tenant::where('slug', 'trial-alpha')->first();

        if ($trialAlpha === null) {
            $this->markTestSkipped('trial-alpha tenant does not exist in this environment — skipping isolation check.');
        }

        $schemaNameBefore = $trialAlpha->schema_name;
        $statusBefore     = $trialAlpha->status;

        // Provision a completely new tenant.
        $this->provisionTrial('dom-isolation-check', 'Domain Isolation Check');

        // Re-fetch trial-alpha — must be unchanged.
        $trialAlphaAfter = Tenant::where('slug', 'trial-alpha')->first();

        $this->assertNotNull($trialAlphaAfter, 'trial-alpha tenant must still exist after new provisioning');
        $this->assertSame($schemaNameBefore, $trialAlphaAfter->schema_name, 'trial-alpha schema_name must be unchanged');
        $this->assertSame($statusBefore, $trialAlphaAfter->status, 'trial-alpha status must be unchanged');
    }

    /** @test */
    public function trial_beta_schema_is_unaffected_by_new_provisioning(): void
    {
        $trialBeta = Tenant::where('slug', 'trial-beta')->first();

        if ($trialBeta === null) {
            $this->markTestSkipped('trial-beta tenant does not exist in this environment — skipping isolation check.');
        }

        $schemaNameBefore = $trialBeta->schema_name;
        $statusBefore     = $trialBeta->status;

        $this->provisionTrial('dom-beta-isolation', 'Domain Beta Isolation');

        $trialBetaAfter = Tenant::where('slug', 'trial-beta')->first();

        $this->assertNotNull($trialBetaAfter, 'trial-beta tenant must still exist after new provisioning');
        $this->assertSame($schemaNameBefore, $trialBetaAfter->schema_name, 'trial-beta schema_name must be unchanged');
        $this->assertSame($statusBefore, $trialBetaAfter->status, 'trial-beta status must be unchanged');
    }

    // ─── VP-DOM-8 — migrations table exists and records all batches ───────────

    /** @test */
    public function provisioned_tenant_has_migrations_table_in_shared_schema(): void
    {
        $tenant = $this->provisionTrial('dom-mig-table', 'Domain Migrations Table');

        // The tenant_migrations table in the shared schema must have records for this tenant.
        $totalRecords = DB::table('tenant_migrations')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'applied')
            ->count();

        // Minimum: 1 (baseline) + 2 (IAM) + 17+ (domain) = 20+
        $this->assertGreaterThanOrEqual(
            20,
            $totalRecords,
            "tenant_migrations must have at least 20 applied records for a fully provisioned tenant, got {$totalRecords}"
        );
    }

    /** @test */
    public function no_failed_migrations_exist_after_successful_provisioning(): void
    {
        $tenant = $this->provisionTrial('dom-no-failures', 'Domain No Failures');

        $failedCount = DB::table('tenant_migrations')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'failed')
            ->count();

        $this->assertSame(
            0,
            $failedCount,
            "No failed migration records must exist after successful provisioning, found {$failedCount}"
        );
    }

    /** @test */
    public function provisioned_tenant_is_active_after_full_domain_provisioning(): void
    {
        $tenant = $this->provisionTrial('dom-active-check', 'Domain Active Check');

        $this->assertSame(
            'active',
            $tenant->status,
            'Tenant must be active after full provisioning including domain migrations'
        );
    }
}
