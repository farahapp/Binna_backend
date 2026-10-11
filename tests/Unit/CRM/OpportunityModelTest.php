<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\CRM\Opportunity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Opportunity model — TASK-003A CRM Domain/Data Foundation.
 *
 * Verifies lifecycle fields required for Won/Lost events (TASK-003B).
 * OD-015: Pipeline/Kanban NOT tested — deferred.
 */
class OpportunityModelTest extends TestCase
{
    /** @test */
    public function opportunity_uses_tenant_connection(): void
    {
        $this->assertSame(
            'tenant',
            (new Opportunity())->getConnectionName(),
            'Opportunity must use the per-tenant connection (OD-001)'
        );
    }

    /** @test */
    public function opportunity_uses_correct_table(): void
    {
        $this->assertSame('opportunities', (new Opportunity())->getTable());
    }

    /** @test */
    public function opportunity_has_required_fillable_fields(): void
    {
        $fillable = (new Opportunity())->getFillable();

        foreach ([
            'client_id',
            'title',
            'status',
            'won_at',
            'lost_at',
            'lost_reason',
            'tenant_slug',
        ] as $field) {
            $this->assertContains($field, $fillable, "Opportunity must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function is_open_returns_true_only_for_open_status(): void
    {
        $opp = new Opportunity();

        $opp->status = 'open';
        $this->assertTrue($opp->isOpen());

        $opp->status = 'won';
        $this->assertFalse($opp->isOpen());
    }

    /** @test */
    public function is_won_returns_true_only_for_won_status(): void
    {
        $opp = new Opportunity();

        $opp->status = 'won';
        $this->assertTrue($opp->isWon());

        $opp->status = 'open';
        $this->assertFalse($opp->isWon());
    }

    /** @test */
    public function is_lost_returns_true_only_for_lost_status(): void
    {
        $opp = new Opportunity();

        $opp->status = 'lost';
        $this->assertTrue($opp->isLost());

        $opp->status = 'open';
        $this->assertFalse($opp->isLost());
    }

    /** @test */
    public function is_terminal_returns_true_for_terminal_states(): void
    {
        $opp = new Opportunity();

        $opp->status = 'open';
        $this->assertFalse($opp->isTerminal());

        foreach (['won', 'lost', 'cancelled'] as $terminal) {
            $opp->status = $terminal;
            $this->assertTrue($opp->isTerminal(), "Status '{$terminal}' must be terminal");
        }
    }

    /** @test */
    public function opportunity_has_won_at_and_lost_at_lifecycle_fields(): void
    {
        // These fields are required for OpportunityWon/OpportunityLost events (TASK-003B)
        $fillable = (new Opportunity())->getFillable();
        $this->assertContains('won_at', $fillable);
        $this->assertContains('lost_at', $fillable);
        $this->assertContains('lost_reason', $fillable);
    }
}
