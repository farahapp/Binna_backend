<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Foundation\SwitchToTenantSchemaForJob;
use App\Foundation\Tenant;
use App\Foundation\TenantContextResolver;
use App\Notification\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * SendNotificationJobTenantIsolationTest — verifies OI-QUEUE-001 for SendNotificationJob.
 *
 * Proves that SendNotificationJob::middleware() correctly wires
 * SwitchToTenantSchemaForJob so that:
 *  1. The 'tenant' connection is switched to the correct schema before handle().
 *  2. The connection is restored to the shared DB after success.
 *  3. The connection is restored to the shared DB after a job exception.
 *  4. An unknown tenant slug is rejected (no default/wrong schema fallback).
 *  5. An inactive tenant slug is rejected.
 *  6. Sequential jobs for different tenants do not leak schema context.
 *  7. schema_name is resolved from the DB record, not derived from the slug alone.
 *
 * Pattern mirrors QueueTenantSchemaIsolationTest (Accounting domain) but is
 * focused on the Notification job's own middleware() wiring rather than the
 * middleware class in isolation.
 *
 * OI-QUEUE-001 — Queue Workers: Tenant Schema Isolation
 */
class SendNotificationJobTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'tenant'];
    }

    private SwitchToTenantSchemaForJob $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = $this->app->make(SwitchToTenantSchemaForJob::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @var string[] */
    private array $createdSchemas = [];

    private function createActiveTenant(string $slug): Tenant
    {
        $tenant = Tenant::create([
            'slug'   => $slug,
            'name'   => "Tenant {$slug}",
            'status' => 'active',
        ]);
        $tenant->schema_name = Tenant::deriveSchemaName($slug);
        $tenant->save();

        $schemaName = $tenant->schema_name;
        DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$schemaName}`");
        $this->createdSchemas[] = $schemaName;

        return $tenant;
    }

    protected function tearDown(): void
    {
        // Restore tenant connection config to the shared DB BEFORE parent::tearDown()
        // so that RefreshDatabase can roll back the 'tenant' transaction against the
        // shared schema (not a tenant schema that may no longer exist).
        $sharedDb = Config::get('database.connections.mysql.database');
        Config::set('database.connections.tenant.database', $sharedDb);

        // Let parent::tearDown() (RefreshDatabase) roll back its transactions first.
        parent::tearDown();

        // Only after the transaction rollback: purge the connection and drop schemas.
        DB::purge('tenant');

        foreach ($this->createdSchemas as $schema) {
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$schema}`");
        }
        $this->createdSchemas = [];
    }

    private function currentTenantDatabase(): string
    {
        return Config::get('database.connections.tenant.database');
    }

    /**
     * Invoke the middleware stack returned by SendNotificationJob::middleware()
     * and call $innerCallback as the "job body" so we can assert schema state.
     */
    private function runJobMiddleware(string $tenantSlug, callable $innerCallback): void
    {
        // Build a minimal job instance — notificationId is irrelevant for middleware tests.
        $job = new SendNotificationJob(
            notificationId: 'test-notification-id',
            tenantSlug: $tenantSlug,
        );

        $middlewares = $job->middleware();

        // There must be exactly one middleware closure.
        $this->assertCount(1, $middlewares, 'SendNotificationJob::middleware() must return exactly one middleware.');

        $middlewareClosure = $middlewares[0];

        // Invoke the middleware with the job and the inner callback as $next.
        $middlewareClosure($job, $innerCallback);
    }

    // =========================================================================
    // Test 1 — middleware switches to the correct schema for a known tenant
    // =========================================================================

    /** @test */
    public function middleware_switches_tenant_connection_to_correct_schema(): void
    {
        $tenant = $this->createActiveTenant('notif-iso-a');

        $capturedDatabase = null;

        $this->runJobMiddleware('notif-iso-a', function () use (&$capturedDatabase): void {
            $capturedDatabase = $this->currentTenantDatabase();
        });

        $this->assertSame(
            Tenant::deriveSchemaName('notif-iso-a'),
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
        $this->createActiveTenant('notif-iso-restore');

        $sharedDatabase = Config::get('database.connections.mysql.database');

        $this->runJobMiddleware('notif-iso-restore', fn () => null);

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
        $this->createActiveTenant('notif-iso-fail');

        $sharedDatabase = Config::get('database.connections.mysql.database');

        try {
            $this->runJobMiddleware('notif-iso-fail', function (): void {
                throw new RuntimeException('Simulated job failure');
            });
        } catch (RuntimeException) {
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

        $this->runJobMiddleware('does-not-exist-notif-xyz', fn () => null);
    }

    // =========================================================================
    // Test 5 — middleware rejects inactive tenant
    // =========================================================================

    /** @test */
    public function middleware_throws_for_inactive_tenant(): void
    {
        $tenant = Tenant::create([
            'slug'   => 'notif-iso-inactive',
            'name'   => 'Inactive Notification Tenant',
            'status' => 'suspended',
        ]);
        $tenant->schema_name = Tenant::deriveSchemaName('notif-iso-inactive');
        $tenant->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not active/i');

        $this->runJobMiddleware('notif-iso-inactive', fn () => null);
    }

    // =========================================================================
    // Test 6 — sequential jobs for different tenants do not leak schema context
    // =========================================================================

    /** @test */
    public function sequential_jobs_for_different_tenants_do_not_leak_schema(): void
    {
        $this->createActiveTenant('notif-seq-a');
        $this->createActiveTenant('notif-seq-b');

        $schemaSeenByJobA = null;
        $schemaSeenByJobB = null;

        $this->runJobMiddleware('notif-seq-a', function () use (&$schemaSeenByJobA): void {
            $schemaSeenByJobA = $this->currentTenantDatabase();
        });

        $this->runJobMiddleware('notif-seq-b', function () use (&$schemaSeenByJobB): void {
            $schemaSeenByJobB = $this->currentTenantDatabase();
        });

        $this->assertSame(Tenant::deriveSchemaName('notif-seq-a'), $schemaSeenByJobA);
        $this->assertSame(Tenant::deriveSchemaName('notif-seq-b'), $schemaSeenByJobB);
        $this->assertNotSame(
            $schemaSeenByJobA,
            $schemaSeenByJobB,
            'Sequential jobs for different tenants must each see their own schema.'
        );
    }

    // =========================================================================
    // Test 7 — schema_name is resolved from DB, not derived from slug alone
    // =========================================================================

    /** @test */
    public function schema_name_is_resolved_from_database_not_derived_from_slug(): void
    {
        $tenant = Tenant::create([
            'slug'   => 'notif-iso-custom',
            'name'   => 'Custom Schema Notification Tenant',
            'status' => 'active',
        ]);
        // Intentionally set a custom schema_name that differs from the derived one.
        $tenant->schema_name = 'binaa2_tenant_notif_custom_override';
        $tenant->save();

        DB::connection('mysql')->statement('CREATE DATABASE IF NOT EXISTS `binaa2_tenant_notif_custom_override`');
        $this->createdSchemas[] = 'binaa2_tenant_notif_custom_override';

        $capturedDatabase = null;

        $this->runJobMiddleware('notif-iso-custom', function () use (&$capturedDatabase): void {
            $capturedDatabase = $this->currentTenantDatabase();
        });

        // Must use the DB-stored schema_name, not the slug-derived one.
        $this->assertSame(
            'binaa2_tenant_notif_custom_override',
            $capturedDatabase,
            'schema_name must come from the database record, not be derived from the slug.'
        );
        $this->assertNotSame(
            Tenant::deriveSchemaName('notif-iso-custom'),
            $capturedDatabase,
        );
    }
}
