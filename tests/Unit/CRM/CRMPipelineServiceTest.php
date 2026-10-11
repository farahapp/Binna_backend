<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\Audit\AuditService;
use App\CRM\CRMService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * CRMPipelineServiceTest — Unit tests for CAP-020 CRM Pipeline methods.
 *
 * Tests:
 *  - LEAD_STAGES constant
 *  - OPPORTUNITY_STAGES constant
 *  - listLeads() throws for invalid stage
 *  - listOpportunities() throws for invalid stage
 *  - updateLeadStage() throws for empty leadId
 *  - updateLeadStage() throws for invalid stage
 *  - updateOpportunityStage() throws for empty opportunityId
 *  - updateOpportunityStage() throws for invalid stage
 *
 * CAP-020 — CRM Pipeline Implementation
 */
class CRMPipelineServiceTest extends TestCase
{
    // =========================================================================
    // CONSTANTS
    // =========================================================================

    public function test_LEAD_STAGES_constant_contains_expected_values(): void
    {
        $expected = ['new', 'contacted', 'qualified', 'converted', 'lost'];

        $this->assertSame($expected, CRMService::LEAD_STAGES);
    }

    public function test_OPPORTUNITY_STAGES_constant_contains_expected_values(): void
    {
        $expected = ['open', 'won', 'lost', 'cancelled'];

        $this->assertSame($expected, CRMService::OPPORTUNITY_STAGES);
    }

    // =========================================================================
    // listLeads()
    // =========================================================================

    public function test_listLeads_throws_for_invalid_stage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $auditServiceMock = $this->createMock(AuditService::class);
        $service = new CRMService($auditServiceMock);

        $service->listLeads('invalid_stage');
    }

    // =========================================================================
    // listOpportunities()
    // =========================================================================

    public function test_listOpportunities_throws_for_invalid_stage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $auditServiceMock = $this->createMock(AuditService::class);
        $service = new CRMService($auditServiceMock);

        $service->listOpportunities('invalid_stage');
    }

    // =========================================================================
    // updateLeadStage()
    // =========================================================================

    public function test_updateLeadStage_throws_for_empty_leadId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $auditServiceMock = $this->createMock(AuditService::class);
        $service = new CRMService($auditServiceMock);

        $service->updateLeadStage('', 'new');
    }

    public function test_updateLeadStage_throws_for_invalid_stage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $auditServiceMock = $this->createMock(AuditService::class);
        $service = new CRMService($auditServiceMock);

        $service->updateLeadStage('some-id', 'invalid');
    }

    // =========================================================================
    // updateOpportunityStage()
    // =========================================================================

    public function test_updateOpportunityStage_throws_for_empty_opportunityId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $auditServiceMock = $this->createMock(AuditService::class);
        $service = new CRMService($auditServiceMock);

        $service->updateOpportunityStage('', 'open');
    }

    public function test_updateOpportunityStage_throws_for_invalid_stage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $auditServiceMock = $this->createMock(AuditService::class);
        $service = new CRMService($auditServiceMock);

        $service->updateOpportunityStage('some-id', 'invalid');
    }
}
