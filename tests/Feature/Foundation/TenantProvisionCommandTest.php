<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\Tenant;
use App\Foundation\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the tenant:provision Artisan command.
 *
 * All tests use Mockery to mock TenantProvisioner — no tenant is actually
 * created, no schema is written, no production database is touched.
 *
 * Coverage:
 *   TC-CMD-01  Missing --slug → failure
 *   TC-CMD-02  Missing --name → failure
 *   TC-CMD-03  Invalid slug format → failure
 *   TC-CMD-04  Slug conflict (existing tenant) → failure
 *   TC-CMD-05  Schema conflict (schema already exists in DB) → failure
 *   TC-CMD-06  Production env without --confirm → failure
 *   TC-CMD-07  Production env with --confirm → provisioner called
 *   TC-CMD-08  Non-production env → provisioner called without --confirm
 *   TC-CMD-09  Provisioner throws InvalidArgumentException → failure
 *   TC-CMD-10  Provisioner throws RuntimeException → failure
 *   TC-CMD-11  Successful run → success exit code, output contains slug/name/status
 */
class TenantProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // TC-CMD-01 — Missing --slug
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_slug_is_missing(): void
    {
        $this->artisan('tenant:provision', ['--name' => 'Acme Corp'])
            ->assertFailed()
            ->expectsOutputToContain('--slug is required');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-02 — Missing --name
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_name_is_missing(): void
    {
        $this->artisan('tenant:provision', ['--slug' => 'acme-test'])
            ->assertFailed()
            ->expectsOutputToContain('--name is required');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-03 — Invalid slug format
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_slug_is_too_short(): void
    {
        $this->artisan('tenant:provision', ['--slug' => 'ab', '--name' => 'AB Corp'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid slug');
    }

    /** @test */
    public function it_fails_when_slug_starts_with_hyphen(): void
    {
        $this->artisan('tenant:provision', ['--slug' => '-bad-slug', '--name' => 'Bad'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid slug');
    }

    /** @test */
    public function it_fails_when_slug_ends_with_hyphen(): void
    {
        $this->artisan('tenant:provision', ['--slug' => 'bad-slug-', '--name' => 'Bad'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid slug');
    }

    /** @test */
    public function it_fails_when_slug_contains_uppercase(): void
    {
        $this->artisan('tenant:provision', ['--slug' => 'BadSlug', '--name' => 'Bad'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid slug');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-04 — Slug conflict
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_slug_already_exists(): void
    {
        // Insert a tenant row directly — no schema creation needed.
        DB::table('tenants')->insert([
            'slug'        => 'existing-slug',
            'name'        => 'Existing Corp',
            'schema_name' => 'binaa2_tenant_existing_slug',
            'status'      => 'active',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->artisan('tenant:provision', [
            '--slug' => 'existing-slug',
            '--name' => 'Existing Corp',
        ])
            ->assertFailed()
            ->expectsOutputToContain('already taken');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-05 — Schema conflict
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_when_derived_schema_already_exists_in_database(): void
    {
        // Mock the DB::select call for information_schema so we can simulate
        // a pre-existing schema without actually creating one.
        DB::shouldReceive('select')
            ->once()
            ->with(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                ['binaa2_tenant_schema_conflict']
            )
            ->andReturn([['SCHEMA_NAME' => 'binaa2_tenant_schema_conflict']]);

        $this->artisan('tenant:provision', [
            '--slug' => 'schema-conflict',
            '--name' => 'Schema Conflict Corp',
        ])
            ->assertFailed()
            ->expectsOutputToContain('already exists');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-06 — Production without --confirm
    // -------------------------------------------------------------------------

    /** @test */
    public function it_refuses_to_run_in_production_without_confirm_flag(): void
    {
        // Temporarily switch to production environment.
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->artisan('tenant:provision', [
                '--slug' => 'prod-tenant',
                '--name' => 'Prod Tenant',
            ])
                ->assertFailed()
                ->expectsOutputToContain('--confirm');
        } finally {
            // Restore testing environment.
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    // -------------------------------------------------------------------------
    // TC-CMD-07 — Production with --confirm → provisioner called
    // -------------------------------------------------------------------------

    /** @test */
    public function it_calls_provisioner_in_production_when_confirm_flag_is_passed(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $mockTenant = $this->makeMockTenant('prod-confirmed', 'Prod Confirmed');

            $mock = \Mockery::mock(TenantProvisioner::class);
            $mock->shouldReceive('provision')
                ->once()
                ->with('prod-confirmed', 'Prod Confirmed')
                ->andReturn($mockTenant);

            $this->app->instance(TenantProvisioner::class, $mock);

            $this->artisan('tenant:provision', [
                '--slug'    => 'prod-confirmed',
                '--name'    => 'Prod Confirmed',
                '--confirm' => true,
            ])
                ->assertSuccessful()
                ->expectsOutputToContain('prod-confirmed');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    // -------------------------------------------------------------------------
    // TC-CMD-08 — Non-production → provisioner called without --confirm
    // -------------------------------------------------------------------------

    /** @test */
    public function it_calls_provisioner_in_non_production_without_confirm(): void
    {
        $mockTenant = $this->makeMockTenant('new-tenant', 'New Tenant Corp');

        $mock = \Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->with('new-tenant', 'New Tenant Corp')
            ->andReturn($mockTenant);

        $this->app->instance(TenantProvisioner::class, $mock);

        $this->artisan('tenant:provision', [
            '--slug' => 'new-tenant',
            '--name' => 'New Tenant Corp',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('new-tenant')
            ->expectsOutputToContain('active');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-09 — Provisioner throws InvalidArgumentException
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_gracefully_when_provisioner_throws_invalid_argument(): void
    {
        $mock = \Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->andThrow(new \InvalidArgumentException('Tenant slug [dup] is already taken.'));

        $this->app->instance(TenantProvisioner::class, $mock);

        $this->artisan('tenant:provision', [
            '--slug' => 'dup-slug',
            '--name' => 'Dup Corp',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Provisioning rejected');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-10 — Provisioner throws RuntimeException
    // -------------------------------------------------------------------------

    /** @test */
    public function it_fails_gracefully_when_provisioner_throws_runtime_exception(): void
    {
        $mock = \Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->andThrow(new \RuntimeException('Tenant provisioning failed for slug [infra-fail]: DB error'));

        $this->app->instance(TenantProvisioner::class, $mock);

        $this->artisan('tenant:provision', [
            '--slug' => 'infra-fail',
            '--name' => 'Infra Fail Corp',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Provisioning failed');
    }

    // -------------------------------------------------------------------------
    // TC-CMD-11 — Successful run output
    // -------------------------------------------------------------------------

    /** @test */
    public function it_outputs_tenant_details_on_success_without_exposing_schema_name(): void
    {
        $mockTenant = $this->makeMockTenant('output-check', 'Output Check Corp');

        $mock = \Mockery::mock(TenantProvisioner::class);
        $mock->shouldReceive('provision')
            ->once()
            ->with('output-check', 'Output Check Corp')
            ->andReturn($mockTenant);

        $this->app->instance(TenantProvisioner::class, $mock);

        $result = $this->artisan('tenant:provision', [
            '--slug' => 'output-check',
            '--name' => 'Output Check Corp',
        ]);

        $result->assertSuccessful()
            ->expectsOutputToContain('output-check')
            ->expectsOutputToContain('Output Check Corp')
            ->expectsOutputToContain('active');

        // schema_name must NOT appear in output (it is in $hidden on Tenant model).
        // We verify the command does not print it by checking the output does not
        // contain the derived schema name.
        $result->doesntExpectOutputToContain('binaa2_tenant_output_check');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal Tenant-like object for use in mock returns.
     * Uses a real Tenant instance with attributes set directly to avoid DB writes.
     */
    private function makeMockTenant(string $slug, string $name): Tenant
    {
        $tenant = new Tenant();
        $tenant->id     = 999;
        $tenant->slug   = $slug;
        $tenant->name   = $name;
        $tenant->status = 'active';
        // schema_name is set directly (not via fillable) — mirrors provisioner behaviour.
        $tenant->schema_name = Tenant::deriveSchemaName($slug);

        return $tenant;
    }
}
