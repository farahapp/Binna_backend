<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\CRM\CRMActivity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CRMActivity model — TASK-003A CRM Domain/Data Foundation.
 */
class CRMActivityModelTest extends TestCase
{
    /** @test */
    public function crm_activity_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new CRMActivity())->getConnectionName(),
            'CRMActivity must use the per-tenant connection (OD-001)'
        );
    }

    /** @test */
    public function crm_activity_uses_correct_table(): void
    {
        $this->assertSame('crm_activities', (new CRMActivity())->getTable());
    }

    /** @test */
    public function crm_activity_has_required_fillable_fields(): void
    {
        $fillable = (new CRMActivity())->getFillable();

        foreach ([
            'subject_type',
            'subject_id',
            'type',
            'summary',
            'occurred_at',
            'actor_id',
            'tenant_slug',
        ] as $field) {
            $this->assertContains($field, $fillable, "CRMActivity must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function crm_activity_has_polymorphic_subject_fields(): void
    {
        // subject_type + subject_id support polymorphic link to Client, Lead, Opportunity
        $fillable = (new CRMActivity())->getFillable();
        $this->assertContains('subject_type', $fillable);
        $this->assertContains('subject_id', $fillable);
    }
}
