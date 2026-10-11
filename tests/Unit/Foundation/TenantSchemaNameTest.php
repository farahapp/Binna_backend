<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use App\Foundation\Tenant;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Tenant::deriveSchemaName().
 *
 * These tests run without a database connection.
 */
class TenantSchemaNameTest extends TestCase
{
    /** @test */
    public function it_derives_a_safe_schema_name_from_a_simple_slug(): void
    {
        $this->assertSame('binaa2_tenant_acme', Tenant::deriveSchemaName('acme'));
    }

    /** @test */
    public function it_converts_hyphens_to_underscores(): void
    {
        $this->assertSame('binaa2_tenant_my_company', Tenant::deriveSchemaName('my-company'));
    }

    /** @test */
    public function it_lowercases_the_slug(): void
    {
        $this->assertSame('binaa2_tenant_acme', Tenant::deriveSchemaName('ACME'));
    }

    /** @test */
    public function it_strips_special_characters(): void
    {
        $name = Tenant::deriveSchemaName('acme!@#$%^&*()');
        $this->assertStringStartsWith('binaa2_tenant_', $name);
        $this->assertMatchesRegularExpression('/^binaa2_tenant_[a-z0-9_]+$/', $name);
    }

    /** @test */
    public function schema_name_always_starts_with_binaa2_tenant_prefix(): void
    {
        foreach (['alpha', 'beta-corp', 'gamma123', 'delta_co'] as $slug) {
            $this->assertStringStartsWith(
                'binaa2_tenant_',
                Tenant::deriveSchemaName($slug),
                "Schema name for slug [{$slug}] must start with binaa2_tenant_"
            );
        }
    }

    /** @test */
    public function schema_name_contains_only_safe_characters(): void
    {
        $name = Tenant::deriveSchemaName('some-tenant-slug');
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9_]+$/',
            $name,
            'Schema name must only contain lowercase alphanumeric and underscores'
        );
    }
}
