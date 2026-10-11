<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\CRM\Client;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Client model — TASK-003A CRM Domain/Data Foundation.
 *
 * Tests model configuration, fillable fields, and status helpers.
 * No database connection required.
 */
class ClientModelTest extends TestCase
{
    /** @test */
    public function client_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new Client())->getConnectionName(),
            'Client must use the per-tenant connection (OD-001)'
        );
    }

    /** @test */
    public function client_uses_correct_table(): void
    {
        $this->assertSame('clients', (new Client())->getTable());
    }

    /** @test */
    public function client_has_required_fillable_fields(): void
    {
        $fillable = (new Client())->getFillable();

        foreach ([
            'type',
            'name',
            'email',
            'phone',
            'status',
            'tenant_slug',
        ] as $field) {
            $this->assertContains($field, $fillable, "Client must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function is_active_returns_true_only_for_active_status(): void
    {
        $client = new Client();

        $client->status = 'active';
        $this->assertTrue($client->isActive());

        $client->status = 'inactive';
        $this->assertFalse($client->isActive());

        $client->status = 'blocked';
        $this->assertFalse($client->isActive());
    }

    /** @test */
    public function is_company_returns_true_only_for_company_type(): void
    {
        $client = new Client();

        $client->type = 'company';
        $this->assertTrue($client->isCompany());
        $this->assertFalse($client->isIndividual());
    }

    /** @test */
    public function is_individual_returns_true_only_for_individual_type(): void
    {
        $client = new Client();

        $client->type = 'individual';
        $this->assertTrue($client->isIndividual());
        $this->assertFalse($client->isCompany());
    }

    /** @test */
    public function client_tenant_slug_is_in_fillable(): void
    {
        $this->assertContains(
            'tenant_slug',
            (new Client())->getFillable(),
            'tenant_slug must be fillable for cross-schema traceability (OD-001)'
        );
    }
}
