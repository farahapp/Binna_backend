<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use App\Foundation\TenantContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TenantContext value object.
 *
 * No database required.
 */
class TenantContextTest extends TestCase
{
    /** @test */
    public function it_reports_active_status_correctly(): void
    {
        $ctx = new TenantContext(
            id: 1,
            slug: 'acme',
            name: 'Acme Corp',
            schemaName: 'binaa2_tenant_acme',
            status: 'active',
        );

        $this->assertTrue($ctx->isActive());
    }

    /** @test */
    public function it_reports_non_active_status_correctly(): void
    {
        foreach (['pending', 'suspended'] as $status) {
            $ctx = new TenantContext(
                id: 1,
                slug: 'acme',
                name: 'Acme Corp',
                schemaName: 'binaa2_tenant_acme',
                status: $status,
            );

            $this->assertFalse($ctx->isActive(), "Status [{$status}] should not be active");
        }
    }

    /** @test */
    public function schema_name_is_accessible_on_context(): void
    {
        $ctx = new TenantContext(
            id: 42,
            slug: 'beta',
            name: 'Beta Ltd',
            schemaName: 'binaa2_tenant_beta',
            status: 'active',
        );

        $this->assertSame('binaa2_tenant_beta', $ctx->schemaName);
    }

    /** @test */
    public function context_is_immutable(): void
    {
        $ctx = new TenantContext(
            id: 1,
            slug: 'acme',
            name: 'Acme',
            schemaName: 'binaa2_tenant_acme',
            status: 'active',
        );

        // Readonly properties cannot be reassigned — verify via reflection.
        $ref = new \ReflectionClass($ctx);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property [{$prop->getName()}] must be readonly");
        }
    }
}
