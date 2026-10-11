<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\Tenant;
use App\Foundation\TenantContext;
use App\Foundation\TenantContextResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests covering TASK-001A Verification Point 4:
 *   VP4 — Tenant A cannot access data belonging to Tenant B.
 *
 * The isolation guarantee at TASK-001A level is that:
 *  - The TenantContextResolver resolves ONLY the tenant matching the slug.
 *  - The resolved TenantContext carries the schema_name from the DB record.
 *  - Two different slugs always resolve to two different schema_names.
 *  - There is no mechanism for a request to obtain another tenant's context.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function two_tenants_have_different_schema_names(): void
    {
        $tenantA = Tenant::create(['slug' => 'tenant-a', 'name' => 'Tenant A', 'status' => 'active']);
        $tenantA->schema_name = Tenant::deriveSchemaName('tenant-a');
        $tenantA->save();

        $tenantB = Tenant::create(['slug' => 'tenant-b', 'name' => 'Tenant B', 'status' => 'active']);
        $tenantB->schema_name = Tenant::deriveSchemaName('tenant-b');
        $tenantB->save();

        $this->assertNotSame(
            $tenantA->schema_name,
            $tenantB->schema_name,
            'Each tenant must have a unique schema_name'
        );
    }

    /** @test */
    public function resolver_returns_correct_context_for_each_slug(): void
    {
        $tenantA = Tenant::create(['slug' => 'iso-a', 'name' => 'Iso A', 'status' => 'active']);
        $tenantA->schema_name = Tenant::deriveSchemaName('iso-a');
        $tenantA->save();

        $tenantB = Tenant::create(['slug' => 'iso-b', 'name' => 'Iso B', 'status' => 'active']);
        $tenantB->schema_name = Tenant::deriveSchemaName('iso-b');
        $tenantB->save();

        $resolver = $this->app->make(TenantContextResolver::class);

        $ctxA = $resolver->resolve('iso-a');
        $ctxB = $resolver->resolve('iso-b');

        $this->assertSame('iso-a', $ctxA->slug);
        $this->assertSame('iso-b', $ctxB->slug);
        $this->assertNotSame($ctxA->schemaName, $ctxB->schemaName);
        $this->assertSame('binaa2_tenant_iso_a', $ctxA->schemaName);
        $this->assertSame('binaa2_tenant_iso_b', $ctxB->schemaName);
    }

    /** @test */
    public function resolver_cannot_return_tenant_b_context_when_given_tenant_a_slug(): void
    {
        $tenantA = Tenant::create(['slug' => 'cross-a', 'name' => 'Cross A', 'status' => 'active']);
        $tenantA->schema_name = Tenant::deriveSchemaName('cross-a');
        $tenantA->save();

        $tenantB = Tenant::create(['slug' => 'cross-b', 'name' => 'Cross B', 'status' => 'active']);
        $tenantB->schema_name = Tenant::deriveSchemaName('cross-b');
        $tenantB->save();

        $resolver = $this->app->make(TenantContextResolver::class);
        $ctxA = $resolver->resolve('cross-a');

        // The context for slug "cross-a" must never contain Tenant B's data.
        $this->assertNotSame($tenantB->id, $ctxA->id);
        $this->assertNotSame($tenantB->schema_name, $ctxA->schemaName);
        $this->assertNotSame('cross-b', $ctxA->slug);
    }

    /** @test */
    public function resolver_throws_for_unknown_slug(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $resolver = $this->app->make(TenantContextResolver::class);
        $resolver->resolve('does-not-exist');
    }
}
