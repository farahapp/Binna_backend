<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Accounting\Account;
use App\Accounting\AccountingPeriod;
use App\Accounting\JournalEntry;
use App\Accounting\PostingRule;
use App\Finance\Events\PaymentReceived;
use App\Foundation\SwitchToTenantSchemaForJob;
use App\Foundation\Tenant;
use App\Foundation\TenantContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * QueueTenantSchemaIsolationTest — verifies OI-QUEUE-001.
 *
 * Proves that SwitchToTenantSchemaForJob:
 *  1. Switches the 'tenant' connection to the correct schema before the job.
 *  2. Restores the connection to a safe state after the job (success or failure).
 *  3. Rejects unknown tenants — never writes to a default/wrong schema.
 *  4. Rejects inactive tenants.
 *  5. Sequential jobs for different tenants do not leak schema context.
 *
 * NOTE: In the test environment both 'mysql' and 'tenant' point to
 * binaa2_testing (TENANT_DB_DATABASE=binaa2_testing in phpunit.xml).
 * The tests verify that SwitchToTenantSchemaForJob correctly resolves
 * schema_name from the DB and issues the USE statement — the actual
 * schema switch is observable via Config and DB::connection('tenant').
 *
 * OI-QUEUE-001 — Queue Workers: Tenant Schema Isolation
 * Authorization: OI-QUEUE-001 fix — 2026-10-16
 */
class QueueTenantSchemaIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'tenant'];
    }

    private TenantContextResolver $resolver;
    private SwitchToTenantSchemaForJob $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver   = $this->app->make(TenantContextResolver::class);
        $this->middleware = $this->app->make(SwitchToTenantSchemaForJob::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createActiveTenant(string $slug): Tenant
    {
        $tenant = Tenant::create([
            'slug'   => $slug,
            'name'   => "Tenant {$slug}",
            'status' => 'active',
        ]);
        $tenant->schema_name = Tenant::deriveSchemaName($slug);
        $tenant->save();

        // Create the schema so that SwitchToTenantSchemaForJob can USE it.
        // In production the schema is created by TenantProvisioner; in tests
        // we create it here and drop it in tearDown to keep the environment clean.
        $schemaName = $tenant->schema_name;
        DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$schemaName}`");
        $this->createdSchemas[] = $schemaName;

        return $tenant;
    }

    /** @var string[] */
    private array $createdSchemas = [];

    protected function tearDown(): void
    {
        // Restore tenant connection to shared DB before teardown.
        $sharedDb = Config::get('database.connections.mysql.database');
        Config::set('database.connections.tenant.database', $sharedDb);
        DB::purge('tenant');

        // Drop any tenant schemas created during this test.
        foreach ($this->createdSchemas as $schema) {
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$schema}`");
        }
        $this->createdSchemas = [];

        parent::tearDown();
    }

    private function currentTenantDatabase(): string
    {
        return Config::get('database.connections.tenant.database');
    }

    // =========================================================================
    // Test 1 — middleware switches to the correct schema for a known tenant
    // =========================================================================

    /** @test */
    public function middleware_switches_tenant_connection_to_correct_schema(): void
    {
        $tenant = $this->createActiveTenant('queue-iso-a');

        $capturedDatabase = null;

        $this->middleware->handle(
            job: new \stdClass(),
            next: function () use (&$capturedDatabase): void {
                $capturedDatabase = $this->currentTenantDatabase();
            },
            tenantSlug: 'queue-iso-a',
        );

        $this->assertSame(
            Tenant::deriveSchemaName('queue-iso-a'),
            $capturedDatabase,
            'The tenant connection must point at the correct schema during job execution.'
        );
    }

    // =========================================================================
    // Test 2 — middleware restores connection to shared DB after job success
    // =========================================================================

    /** @test */
    public function middleware_restores_connection_to_shared_db_after_success(): void
    {
        $this->createActiveTenant('queue-iso-restore');

        $sharedDatabase = Config::get('database.connections.mysql.database');

        $this->middleware->handle(
            job: new \stdClass(),
            next: fn () => null,
            tenantSlug: 'queue-iso-restore',
        );

        $this->assertSame(
            $sharedDatabase,
            $this->currentTenantDatabase(),
            'The tenant connection must be restored to the shared DB after job success.'
        );
    }

    // =========================================================================
    // Test 3 — middleware restores connection after job exception
    // =========================================================================

    /** @test */
    public function middleware_restores_connection_after_job_exception(): void
    {
        $this->createActiveTenant('queue-iso-fail');

        $sharedDatabase = Config::get('database.connections.mysql.database');

        try {
            $this->middleware->handle(
                job: new \stdClass(),
                next: function (): void {
                    throw new \RuntimeException('Simulated job failure');
                },
                tenantSlug: 'queue-iso-fail',
            );
        } catch (\RuntimeException) {
            // Expected — the job threw.
        }

        $this->assertSame(
            $sharedDatabase,
            $this->currentTenantDatabase(),
            'The tenant connection must be restored to the shared DB even after a job exception.'
        );
    }

    // =========================================================================
    // Test 4 — middleware rejects unknown tenant slug
    // =========================================================================

    /** @test */
    public function middleware_throws_for_unknown_tenant_slug(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenant not found/i');

        $this->middleware->handle(
            job: new \stdClass(),
            next: fn () => null,
            tenantSlug: 'does-not-exist-xyz',
        );
    }

    // =========================================================================
    // Test 5 — middleware rejects inactive tenant
    // =========================================================================

    /** @test */
    public function middleware_throws_for_inactive_tenant(): void
    {
        $tenant = Tenant::create([
            'slug'   => 'queue-iso-inactive',
            'name'   => 'Inactive Tenant',
            'status' => 'suspended',
        ]);
        $tenant->schema_name = Tenant::deriveSchemaName('queue-iso-inactive');
        $tenant->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not active/i');

        $this->middleware->handle(
            job: new \stdClass(),
            next: fn () => null,
            tenantSlug: 'queue-iso-inactive',
        );
    }

    // =========================================================================
    // Test 6 — sequential jobs for different tenants do not leak schema context
    // =========================================================================

    /** @test */
    public function sequential_jobs_for_different_tenants_do_not_leak_schema(): void
    {
        $this->createActiveTenant('queue-seq-a');
        $this->createActiveTenant('queue-seq-b');

        $schemaSeenByJobA = null;
        $schemaSeenByJobB = null;

        // Job for tenant A
        $this->middleware->handle(
            job: new \stdClass(),
            next: function () use (&$schemaSeenByJobA): void {
                $schemaSeenByJobA = $this->currentTenantDatabase();
            },
            tenantSlug: 'queue-seq-a',
        );

        // Job for tenant B — must NOT see tenant A's schema
        $this->middleware->handle(
            job: new \stdClass(),
            next: function () use (&$schemaSeenByJobB): void {
                $schemaSeenByJobB = $this->currentTenantDatabase();
            },
            tenantSlug: 'queue-seq-b',
        );

        $this->assertSame(Tenant::deriveSchemaName('queue-seq-a'), $schemaSeenByJobA);
        $this->assertSame(Tenant::deriveSchemaName('queue-seq-b'), $schemaSeenByJobB);
        $this->assertNotSame(
            $schemaSeenByJobA,
            $schemaSeenByJobB,
            'Sequential jobs for different tenants must each see their own schema.'
        );
    }

    // =========================================================================
    // Test 7 — empty tenantSlug is rejected (no default schema fallback)
    // =========================================================================

    /** @test */
    public function middleware_throws_for_empty_tenant_slug(): void
    {
        $this->expectException(RuntimeException::class);

        $this->middleware->handle(
            job: new \stdClass(),
            next: fn () => null,
            tenantSlug: '',
        );
    }

    // =========================================================================
    // Test 8 — schema_name is resolved from DB, not derived from slug alone
    // =========================================================================

    /** @test */
    public function schema_name_is_resolved_from_database_not_derived_from_slug(): void
    {
        // Create tenant with a custom schema_name that differs from the derived one.
        $tenant = Tenant::create([
            'slug'   => 'queue-iso-custom',
            'name'   => 'Custom Schema Tenant',
            'status' => 'active',
        ]);
        // Intentionally set a custom schema_name (not the derived one).
        $tenant->schema_name = 'binaa2_tenant_custom_override';
        $tenant->save();

        // Create the custom schema so the middleware can USE it.
        DB::connection('mysql')->statement('CREATE DATABASE IF NOT EXISTS `binaa2_tenant_custom_override`');
        $this->createdSchemas[] = 'binaa2_tenant_custom_override';

        $capturedDatabase = null;

        $this->middleware->handle(
            job: new \stdClass(),
            next: function () use (&$capturedDatabase): void {
                $capturedDatabase = $this->currentTenantDatabase();
            },
            tenantSlug: 'queue-iso-custom',
        );

        // Must use the DB-stored schema_name, not the slug-derived one.
        $this->assertSame(
            'binaa2_tenant_custom_override',
            $capturedDatabase,
            'schema_name must come from the database record, not be derived from the slug.'
        );
        $this->assertNotSame(
            Tenant::deriveSchemaName('queue-iso-custom'),
            $capturedDatabase,
        );
    }
}
