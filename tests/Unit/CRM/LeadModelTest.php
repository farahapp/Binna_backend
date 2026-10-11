<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\CRM\Lead;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Lead model — TASK-003A CRM Domain/Data Foundation.
 */
class LeadModelTest extends TestCase
{
    /** @test */
    public function lead_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new Lead())->getConnectionName(),
            'Lead must use the per-tenant connection (OD-001)'
        );
    }

    /** @test */
    public function lead_uses_correct_table(): void
    {
        $this->assertSame('leads', (new Lead())->getTable());
    }

    /** @test */
    public function lead_has_required_fillable_fields(): void
    {
        $fillable = (new Lead())->getFillable();

        foreach (['name', 'email', 'phone', 'status', 'tenant_slug'] as $field) {
            $this->assertContains($field, $fillable, "Lead must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function is_converted_returns_true_only_for_converted_status(): void
    {
        $lead = new Lead();

        $lead->status = 'converted';
        $this->assertTrue($lead->isConverted());

        $lead->status = 'new';
        $this->assertFalse($lead->isConverted());
    }

    /** @test */
    public function is_new_returns_true_only_for_new_status(): void
    {
        $lead = new Lead();

        $lead->status = 'new';
        $this->assertTrue($lead->isNew());

        $lead->status = 'qualified';
        $this->assertFalse($lead->isNew());
    }

    /** @test */
    public function is_lost_returns_true_only_for_lost_status(): void
    {
        $lead = new Lead();

        $lead->status = 'lost';
        $this->assertTrue($lead->isLost());

        $lead->status = 'new';
        $this->assertFalse($lead->isLost());
    }

    /** @test */
    public function lead_converted_client_id_is_in_fillable(): void
    {
        $this->assertContains('converted_client_id', (new Lead())->getFillable());
    }
}
