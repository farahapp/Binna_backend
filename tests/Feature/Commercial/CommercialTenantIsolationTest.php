<?php

declare(strict_types=1);

namespace Tests\Feature\Commercial;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation tests for Commercial module — TASK-004A.
 *
 * Verifies that all Commercial models use the tenant connection
 * and do not introduce shared-schema Commercial storage.
 */
class CommercialTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function all_commercial_models_use_tenant_connection(): void
    {
        $models = [
            Tender::class,
            TenderInvitation::class,
            TenderResponse::class,
            BOQ::class,
            BOQSection::class,
            BOQItem::class,
            Estimate::class,
            Quotation::class,
            Contract::class,
            ContractVariation::class,
        ];

        foreach ($models as $modelClass) {
            $instance = new $modelClass();
            $this->assertSame(
                'tenant',
                $instance->getConnectionName(),
                "{$modelClass} must use the per-tenant connection — no shared-schema Commercial storage (OD-001)"
            );
        }
    }

    /** @test */
    public function commercial_data_is_stored_in_tenant_connection(): void
    {
        // Verify that Tender records are created on the tenant connection
        $tender = Tender::create([
            'title'       => 'Isolation Test Tender',
            'status'      => 'DRAFT',
            'tenant_slug' => 'isolation-test-tenant',
        ]);

        // Verify it was stored in the tenant schema
        $found = Tender::on('tenant')->find($tender->id);
        $this->assertNotNull($found, 'Tender must be retrievable from the tenant connection');
        $this->assertSame('Isolation Test Tender', $found->title);
    }
}
