<?php

declare(strict_types=1);

namespace Tests\Unit\Commercial;

use App\Commercial\BOQ;
use App\Commercial\BOQItem;
use App\Commercial\BOQSection;
use App\Commercial\Contract;
use App\Commercial\ContractVariation;
use App\Commercial\Estimate;
use App\Commercial\Quotation;
use App\Commercial\Tender;
use App\Commercial\TenderInvitation;
use App\Commercial\TenderResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Commercial models — TASK-004A Commercial Domain/Data Foundation.
 *
 * Verifies model configuration without database connection.
 */
class CommercialModelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Tenant Connection Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function all_commercial_models_use_tenant_connection(): void
    {
        $models = [
            new Tender(),
            new TenderInvitation(),
            new TenderResponse(),
            new BOQ(),
            new BOQSection(),
            new BOQItem(),
            new Estimate(),
            new Quotation(),
            new Contract(),
            new ContractVariation(),
        ];

        foreach ($models as $model) {
            $this->assertSame(
                'tenant',
                $model->getConnectionName(),
                get_class($model) . ' must use the per-tenant connection (OD-001)'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Table Name Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tender_uses_correct_table(): void
    {
        $this->assertSame('tenders', (new Tender())->getTable());
    }

    /** @test */
    public function tender_invitation_uses_correct_table(): void
    {
        $this->assertSame('tender_invitations', (new TenderInvitation())->getTable());
    }

    /** @test */
    public function tender_response_uses_correct_table(): void
    {
        $this->assertSame('tender_responses', (new TenderResponse())->getTable());
    }

    /** @test */
    public function boq_uses_correct_table(): void
    {
        $this->assertSame('boqs', (new BOQ())->getTable());
    }

    /** @test */
    public function boq_section_uses_correct_table(): void
    {
        $this->assertSame('boq_sections', (new BOQSection())->getTable());
    }

    /** @test */
    public function boq_item_uses_correct_table(): void
    {
        $this->assertSame('boq_items', (new BOQItem())->getTable());
    }

    /** @test */
    public function estimate_uses_correct_table(): void
    {
        $this->assertSame('estimates', (new Estimate())->getTable());
    }

    /** @test */
    public function quotation_uses_correct_table(): void
    {
        $this->assertSame('quotations', (new Quotation())->getTable());
    }

    /** @test */
    public function contract_uses_correct_table(): void
    {
        $this->assertSame('contracts', (new Contract())->getTable());
    }

    /** @test */
    public function contract_variation_uses_correct_table(): void
    {
        $this->assertSame('contract_variations', (new ContractVariation())->getTable());
    }

    // -------------------------------------------------------------------------
    // Fillable Fields Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tender_has_required_fillable_fields(): void
    {
        $fillable = (new Tender())->getFillable();
        foreach (['title', 'status', 'tenant_slug'] as $field) {
            $this->assertContains($field, $fillable, "Tender must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function contract_has_required_fillable_fields(): void
    {
        $fillable = (new Contract())->getFillable();
        foreach ([
            'contract_number',
            'client_id',
            'project_id',
            'contract_value',
            'approved_variations_value',
            'revised_contract_value',
            'payment_terms',
            'retention_percentage',
            'start_date',
            'end_date',
            'status',
            'boq_id',
            'tenant_slug',
        ] as $field) {
            $this->assertContains($field, $fillable, "Contract must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function contract_variation_has_required_fillable_fields(): void
    {
        $fillable = (new ContractVariation())->getFillable();
        foreach ([
            'contract_id',
            'variation_number',
            'reason',
            'scope_description',
            'boq_changes',
            'value_change',
            'status',
            'approval_ref',
            'approved_at',
            'tenant_slug',
        ] as $field) {
            $this->assertContains($field, $fillable, "ContractVariation must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function boq_item_has_required_fillable_fields(): void
    {
        $fillable = (new BOQItem())->getFillable();
        foreach ([
            'description',
            'unit',
            'quantity',
            'unit_rate',
            'amount',
            'wbs_reference',
            'tenant_slug',
        ] as $field) {
            $this->assertContains($field, $fillable, "BOQItem must have '{$field}' in fillable");
        }
    }

    /** @test */
    public function all_commercial_models_have_tenant_slug_in_fillable(): void
    {
        $models = [
            new Tender(),
            new TenderInvitation(),
            new TenderResponse(),
            new BOQ(),
            new BOQSection(),
            new BOQItem(),
            new Estimate(),
            new Quotation(),
            new Contract(),
            new ContractVariation(),
        ];

        foreach ($models as $model) {
            $this->assertContains(
                'tenant_slug',
                $model->getFillable(),
                get_class($model) . ' must have tenant_slug in fillable (OD-001 cross-schema traceability)'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Status Helper Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function tender_status_helpers_work_correctly(): void
    {
        $tender = new Tender();

        $tender->status = 'DRAFT';
        $this->assertTrue($tender->isDraft());
        $this->assertFalse($tender->isPublished());

        $tender->status = 'PUBLISHED';
        $this->assertTrue($tender->isPublished());
        $this->assertFalse($tender->isDraft());

        $tender->status = 'AWARDED';
        $this->assertTrue($tender->isAwarded());

        $tender->status = 'CANCELLED';
        $this->assertTrue($tender->isCancelled());
    }

    /** @test */
    public function quotation_status_helpers_work_correctly(): void
    {
        $quotation = new Quotation();

        $quotation->status = 'DRAFT';
        $this->assertTrue($quotation->isDraft());

        $quotation->status = 'ACCEPTED';
        $this->assertTrue($quotation->isAccepted());

        $quotation->status = 'REJECTED';
        $this->assertTrue($quotation->isRejected());

        $quotation->status = 'EXPIRED';
        $this->assertTrue($quotation->isExpired());
    }

    /** @test */
    public function contract_status_helpers_work_correctly(): void
    {
        $contract = new Contract();

        $contract->status = 'DRAFT';
        $this->assertTrue($contract->isDraft());

        $contract->status = 'ACTIVE';
        $this->assertTrue($contract->isActive());

        $contract->status = 'COMPLETED';
        $this->assertTrue($contract->isCompleted());

        $contract->status = 'CLOSED';
        $this->assertTrue($contract->isClosed());

        $contract->status = 'TERMINATED';
        $this->assertTrue($contract->isTerminated());
    }

    /** @test */
    public function contract_variation_status_helpers_work_correctly(): void
    {
        $variation = new ContractVariation();

        $variation->status = 'DRAFT';
        $this->assertTrue($variation->isDraft());

        $variation->status = 'APPROVED';
        $this->assertTrue($variation->isApproved());

        $variation->status = 'REJECTED';
        $this->assertTrue($variation->isRejected());
    }

    /** @test */
    public function boq_type_helpers_work_correctly(): void
    {
        $boq = new BOQ();

        $boq->type = 'tender_boq';
        $this->assertTrue($boq->isTenderBOQ());
        $this->assertFalse($boq->isContractBOQ());

        $boq->type = 'contract_boq';
        $this->assertTrue($boq->isContractBOQ());

        $boq->type = 'variation_boq';
        $this->assertTrue($boq->isVariationBOQ());
    }

    // -------------------------------------------------------------------------
    // JSON Cast Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function contract_variation_boq_changes_is_cast_to_array(): void
    {
        $casts = (new ContractVariation())->getCasts();
        $this->assertArrayHasKey('boq_changes', $casts);
        $this->assertSame('array', $casts['boq_changes']);
    }

    // -------------------------------------------------------------------------
    // Nullable Fields Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function contract_project_id_is_in_fillable_and_nullable(): void
    {
        // project_id must be fillable (nullable — Projects module not yet implemented)
        $this->assertContains(
            'project_id',
            (new Contract())->getFillable(),
            'project_id must be fillable — nullable because Projects module not yet implemented'
        );
    }
}
