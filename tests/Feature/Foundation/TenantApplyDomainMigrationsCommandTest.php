<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\DomainMigrator;
use App\Foundation\Tenant;
use App\Foundation\TenantMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature tests for the tenant:apply-domain-migrations Artisan command.
 *
 * All tests mock DomainMigrator — no real migrations are executed,
 * no schema is written, no production database is touched.
 *
 * Coverage:
 *   TC-ADM-01  Missing --slug → failure
 *   TC-ADM-02  Tenant not found → failure
 *   TC-ADM-03  Tenant not active (pending) → failure
 *   TC-ADM-04  Tenant not active (suspended) → failure
 *   TC-ADM-05  Schema does not exist → failure
 *   TC-ADM-06  batch=3 records already exist without --force → failure
 *   TC-ADM-07  batch=3 records exist with --force → migrator called
 *   TC-ADM-08  Production env without --confirm → failure
 *   TC-ADM-09  Production env with --confirm → migrator called
 *   TC-ADM-10  DomainMigrator throws → failure, error message shown
 *   TC-ADM-11  Successful run → success, applied count shown
 *   TC-ADM-12  --dry-run → success, migrator NOT called
 *   TC-ADM-13  Does not affect other tenants (isolation check)
 */
class TenantApplyDomainMigrationsCommandTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Insert a tenant row directly (no schema creation).
     * schema_name is set to a value that does NOT exist in information_schema
     * unless we explicitly stub the DB::select call.
     */
    private function createTenantRow(
        string $slug,
        string $status = 'active',
        string $schemaName = ''
    ): Tenant {
        if ($schemaName === '') {
            $schemaName = Tenant::deriveSchemaName($slug);
        }

        DB::table('tenants')->insert([
            'slug'        => $slug,
            'name'        => ucfirst($slug) . ' Corp',
            'schema_name' => $schemaName,
            'status'      => $status,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return Tenant::where('slug', $slug)->firstOrFail();
    }

    /**
     * Stub information_schema.SCHEMATA so the command believes the schema exists.
     * We insert a row into the tenants table only — the actual MySQL schema is
     * never created in tests. We use DB::shouldReceive to intercept the
     * information_schema query.
     *
     * NOTE: Because RefreshDatabase wraps everything in a transaction on the
     * 'mysql' connection, we cannot actually CREATE a schema in tests.
     * Instead we mock the schema-existence check.
     */
    private function mockSchemaExists(string $schemaName): void
    {
        // We partial-mock DB::select for the specific information_schema query.
        // All other DB calls pass through normally.
        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$schemaName]
            )
            ->andReturn([(object) ['SCHEMA_NAME' => $schemaName]]);
    }

    /**
     * Stub information_schema.SCHEMATA so the command believes the schema is MISSING.
     */
    private function mockSchemaMissing(string $schemaName): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$schemaName]
            )
            ->andReturn([]);
    }

    // -------------------------------------------------------------------------
    // TC-ADM-01 — Missing --slug
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_slug_is_missing(): void
    {
        $this->artisan('tenant:apply-domain-migrations')
            ->assertFailed()
            ->expectsOutputToContain('--slug is required');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-02 — Tenant not found
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_tenant_does_not_exist(): void
    {
        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'no-such-tenant'])
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-03 — Tenant not active (pending)
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_tenant_is_pending(): void
    {
        $this->createTenantRow('pending-tenant', 'pending');

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'pending-tenant'])
            ->assertFailed()
            ->expectsOutputToContain('has status [pending]');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-04 — Tenant not active (suspended)
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_tenant_is_suspended(): void
    {
        $this->createTenantRow('suspended-tenant', 'suspended');

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'suspended-tenant'])
            ->assertFailed()
            ->expectsOutputToContain('has status [suspended]');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-05 — Schema does not exist
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_schema_does_not_exist(): void
    {
        $tenant = $this->createTenantRow('schema-missing-tenant');

        $this->mockSchemaMissing($tenant->schema_name);

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'schema-missing-tenant'])
            ->assertFailed()
            ->expectsOutputToContain('does not exist');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-06 — batch=3 records already exist without --force
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_batch3_records_exist_without_force(): void
    {
        $tenant = $this->createTenantRow('already-migrated');

        $this->mockSchemaExists($tenant->schema_name);

        // Insert a batch=3 record to simulate already-applied state.
        TenantMigration::create([
            'tenant_id'  => $tenant->id,
            'migration'  => 'hr/2026_10_01_100001_create_employees_table',
            'batch'      => 3,
            'status'     => 'applied',
            'applied_at' => now(),
        ]);

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'already-migrated'])
            ->assertFailed()
            ->expectsOutputToContain('batch=3');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-07 — batch=3 records exist WITH --force → migrator called
    // -------------------------------------------------------------------------

    /** @test */
    public function it_calls_migrator_when_batch3_exists_with_force_flag(): void
    {
        $tenant = $this->createTenantRow('force-tenant');

        $this->mockSchemaExists($tenant->schema_name);

        TenantMigration::create([
            'tenant_id'  => $tenant->id,
            'migration'  => 'hr/2026_10_01_100001_create_employees_table',
            'batch'      => 3,
            'status'     => 'applied',
            'applied_at' => now(),
        ]);

        $this->mock(DomainMigrator::class, function (MockInterface $mock) use ($tenant) {
            $mock->shouldReceive('run')
                ->once()
                ->with(Mockery::on(fn ($t) => $t->id === $tenant->id));
        });

        $this->artisan('tenant:apply-domain-migrations', [
            '--slug'  => 'force-tenant',
            '--force' => true,
        ])->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // TC-ADM-08 — Production env without --confirm → failure
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_in_production_without_confirm(): void
    {
        // Temporarily set APP_ENV to production for this test.
        $this->app['env'] = 'production';

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'auth-smoke'])
            ->assertFailed()
            ->expectsOutputToContain('--confirm');

        // Restore.
        $this->app['env'] = 'testing';
    }

    // -------------------------------------------------------------------------
    // TC-ADM-09 — Production env with --confirm → migrator called
    // -------------------------------------------------------------------------

    /** @test */
    public function it_calls_migrator_in_production_with_confirm(): void
    {
        $tenant = $this->createTenantRow('prod-confirm-tenant');

        $this->mockSchemaExists($tenant->schema_name);

        $this->mock(DomainMigrator::class, function (MockInterface $mock) use ($tenant) {
            $mock->shouldReceive('run')
                ->once()
                ->with(Mockery::on(fn ($t) => $t->id === $tenant->id));
        });

        $this->app['env'] = 'production';

        $this->artisan('tenant:apply-domain-migrations', [
            '--slug'    => 'prod-confirm-tenant',
            '--confirm' => true,
        ])->assertSuccessful();

        $this->app['env'] = 'testing';
    }

    // -------------------------------------------------------------------------
    // TC-ADM-10 — DomainMigrator throws → failure, error message shown
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_and_shows_error_when_migrator_throws(): void
    {
        $tenant = $this->createTenantRow('failing-tenant');

        $this->mockSchemaExists($tenant->schema_name);

        $this->mock(DomainMigrator::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(new \RuntimeException('Connection refused'));
        });

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'failing-tenant'])
            ->assertFailed()
            ->expectsOutputToContain('Connection refused');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-11 — Successful run → success, applied count shown
    // -------------------------------------------------------------------------

    /** @test */
    public function it_succeeds_and_shows_applied_count(): void
    {
        $tenant = $this->createTenantRow('success-tenant');

        $this->mockSchemaExists($tenant->schema_name);

        $this->mock(DomainMigrator::class, function (MockInterface $mock) use ($tenant) {
            $mock->shouldReceive('run')
                ->once()
                ->with(Mockery::on(fn ($t) => $t->id === $tenant->id))
                ->andReturnUsing(function (Tenant $t) {
                    // Simulate DomainMigrator recording 3 batch=3 migrations.
                    foreach (['hr/mig1', 'finance/mig2', 'procurement/mig3'] as $mig) {
                        TenantMigration::create([
                            'tenant_id'  => $t->id,
                            'migration'  => $mig,
                            'batch'      => 3,
                            'status'     => 'applied',
                            'applied_at' => now(),
                        ]);
                    }
                });
        });

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'success-tenant'])
            ->assertSuccessful()
            ->expectsOutputToContain('applied successfully')
            ->expectsOutputToContain('success-tenant')
            ->expectsOutputToContain('3');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-12 — --dry-run → success, migrator NOT called
    // -------------------------------------------------------------------------

    /** @test */
    public function it_succeeds_in_dry_run_without_calling_migrator(): void
    {
        $tenant = $this->createTenantRow('dryrun-tenant');

        $this->mockSchemaExists($tenant->schema_name);

        // DomainMigrator must NOT be called in dry-run mode.
        $this->mock(DomainMigrator::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $this->artisan('tenant:apply-domain-migrations', [
            '--slug'    => 'dryrun-tenant',
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('No migrations were applied');
    }

    // -------------------------------------------------------------------------
    // TC-ADM-13 — Isolation: does not affect other tenants
    // -------------------------------------------------------------------------

    /** @test */
    public function it_does_not_affect_other_tenants(): void
    {
        // Create the target tenant and a bystander tenant.
        $target    = $this->createTenantRow('target-tenant');
        $bystander = $this->createTenantRow('bystander-tenant');

        $this->mockSchemaExists($target->schema_name);

        // DomainMigrator must be called exactly once, with the target tenant only.
        $this->mock(DomainMigrator::class, function (MockInterface $mock) use ($target, $bystander) {
            $mock->shouldReceive('run')
                ->once()
                ->with(Mockery::on(function (Tenant $t) use ($target, $bystander) {
                    // Must be called with target, never with bystander.
                    $this->assertNotEquals($bystander->id, $t->id, 'DomainMigrator must not run on bystander tenant');
                    return $t->id === $target->id;
                }));
        });

        $this->artisan('tenant:apply-domain-migrations', ['--slug' => 'target-tenant'])
            ->assertSuccessful();

        // Bystander must have zero batch=3 records.
        $bystanderBatch3 = TenantMigration::where('tenant_id', $bystander->id)
            ->where('batch', 3)
            ->count();

        $this->assertSame(0, $bystanderBatch3, 'Bystander tenant must not receive any batch=3 records');
    }
}
