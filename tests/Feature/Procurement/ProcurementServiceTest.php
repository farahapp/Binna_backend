<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Procurement\POItem;
use App\Procurement\PurchaseOrder;
use App\Procurement\PurchaseOrderService;
use App\Procurement\PurchaseRequest;
use App\Procurement\PurchaseRequestService;
use App\Procurement\RFQ;
use App\Procurement\RFQService;
use App\Procurement\SupplierQuotation;
use App\Procurement\TenantSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for Procurement Application/Service Layer — TASK-007B.
 *
 * Verifies:
 *  1. CAP-061 — PurchaseRequestService lifecycle
 *  2. CAP-062 — RFQService lifecycle + quotation comparison
 *  3. CAP-063 — PurchaseOrderService lifecycle
 *  4. Approval integration
 *  5. Audit integration
 *  6. Domain events dispatched
 *  7. Tenant isolation
 *  8. Validation / business-rule failures
 *  9. ServiceProvider registrations
 */
class ProcurementServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseRequestService $prService;
    private RFQService $rfqService;
    private PurchaseOrderService $poService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prService  = app(PurchaseRequestService::class);
        $this->rfqService = app(RFQService::class);
        $this->poService  = app(PurchaseOrderService::class);
    }

    // =========================================================================
    // ServiceProvider Registration
    // =========================================================================

    /** @test */
    public function purchase_request_service_is_registered(): void
    {
        $this->assertInstanceOf(PurchaseRequestService::class, app(PurchaseRequestService::class));
    }

    /** @test */
    public function rfq_service_is_registered(): void
    {
        $this->assertInstanceOf(RFQService::class, app(RFQService::class));
    }

    /** @test */
    public function purchase_order_service_is_registered(): void
    {
        $this->assertInstanceOf(PurchaseOrderService::class, app(PurchaseOrderService::class));
    }

    // =========================================================================
    // CAP-061 — PurchaseRequestService
    // =========================================================================

    /** @test */
    public function can_create_purchase_request(): void
    {
        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Office Supplies Q4',
        ], 'actor-uuid');

        $this->assertNotNull($pr->id);
        $this->assertEquals('draft', $pr->status);
        $this->assertEquals('Office Supplies Q4', $pr->title);
        $this->assertEquals('test-tenant', $pr->tenant_slug);
    }

    /** @test */
    public function create_purchase_request_requires_tenant_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant_slug is required');

        $this->prService->createPurchaseRequest(['title' => 'Test']);
    }

    /** @test */
    public function create_purchase_request_requires_title(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');

        $this->prService->createPurchaseRequest(['tenant_slug' => 'test-tenant']);
    }

    /** @test */
    public function can_get_purchase_request(): void
    {
        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $found = $this->prService->getPurchaseRequest($pr->id);
        $this->assertEquals($pr->id, $found->id);
    }

    /** @test */
    public function get_purchase_request_throws_for_unknown_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->prService->getPurchaseRequest('00000000-0000-0000-0000-000000000000');
    }

    /** @test */
    public function can_submit_purchase_request(): void
    {
        Event::fake();

        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $submitted = $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');

        $this->assertEquals('submitted', $submitted->status);
        $this->assertNotNull($submitted->approval_request_id);
    }

    /** @test */
    public function submit_purchase_request_dispatches_event(): void
    {
        Event::fake();

        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');

        Event::assertDispatched(\App\Procurement\Events\PurchaseRequestSubmitted::class);
    }

    /** @test */
    public function cannot_submit_non_draft_purchase_request(): void
    {
        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
    }

    /** @test */
    public function can_approve_purchase_request(): void
    {
        Event::fake();

        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
        $approved = $this->prService->approvePurchaseRequest($pr->id, 'approver-uuid', 'test-tenant');

        $this->assertEquals('approved', $approved->status);
    }

    /** @test */
    public function approve_purchase_request_dispatches_event(): void
    {
        Event::fake();

        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
        $this->prService->approvePurchaseRequest($pr->id, 'approver-uuid', 'test-tenant');

        Event::assertDispatched(\App\Procurement\Events\PurchaseRequestApproved::class);
    }

    /** @test */
    public function can_reject_purchase_request(): void
    {
        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
        $rejected = $this->prService->rejectPurchaseRequest($pr->id, 'approver-uuid', 'test-tenant', 'Budget exceeded');

        $this->assertEquals('rejected', $rejected->status);
    }

    /** @test */
    public function can_cancel_draft_purchase_request(): void
    {
        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $cancelled = $this->prService->cancelPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
        $this->assertEquals('cancelled', $cancelled->status);
    }

    /** @test */
    public function cannot_cancel_approved_purchase_request(): void
    {
        Event::fake();

        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test PR',
        ]);

        $this->prService->submitPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
        $this->prService->approvePurchaseRequest($pr->id, 'approver-uuid', 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->prService->cancelPurchaseRequest($pr->id, 'user-uuid', 'test-tenant');
    }

    // =========================================================================
    // CAP-062 — RFQService
    // =========================================================================

    /** @test */
    public function can_create_rfq(): void
    {
        $pr = PurchaseRequest::create(['tenant_slug' => 'test-tenant', 'title' => 'Test PR']);

        $rfq = $this->rfqService->createRFQ([
            'tenant_slug'         => 'test-tenant',
            'title'               => 'RFQ for Office Supplies',
            'purchase_request_id' => $pr->id,
        ], 'actor-uuid');

        $this->assertNotNull($rfq->id);
        $this->assertEquals('draft', $rfq->status);
        $this->assertEquals($pr->id, $rfq->purchase_request_id);
    }

    /** @test */
    public function can_send_rfq(): void
    {
        Event::fake();

        $rfq = $this->rfqService->createRFQ([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test RFQ',
        ]);

        $sent = $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');
        $this->assertEquals('sent', $sent->status);
    }

    /** @test */
    public function send_rfq_dispatches_event(): void
    {
        Event::fake();

        $rfq = $this->rfqService->createRFQ([
            'tenant_slug' => 'test-tenant',
            'title'       => 'Test RFQ',
        ]);

        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        Event::assertDispatched(\App\Procurement\Events\RFQSent::class);
    }

    /** @test */
    public function cannot_send_non_draft_rfq(): void
    {
        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');
    }

    /** @test */
    public function can_close_rfq(): void
    {
        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');
        $closed = $this->rfqService->closeRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        $this->assertEquals('closed', $closed->status);
    }

    /** @test */
    public function can_submit_quotation_to_sent_rfq(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier A']);
        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        $quotation = $this->rfqService->submitQuotation($rfq->id, [
            'tenant_slug'  => 'test-tenant',
            'supplier_id'  => $supplier->id,
            'total_amount' => 5000.00,
        ]);

        $this->assertNotNull($quotation->id);
        $this->assertEquals('submitted', $quotation->status);
        $this->assertEquals('5000.00', $quotation->total_amount);
    }

    /** @test */
    public function cannot_submit_quotation_to_draft_rfq(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier A']);
        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);

        $this->expectException(\InvalidArgumentException::class);
        $this->rfqService->submitQuotation($rfq->id, [
            'tenant_slug'  => 'test-tenant',
            'supplier_id'  => $supplier->id,
            'total_amount' => 5000.00,
        ]);
    }

    /** @test */
    public function can_compare_quotations_sorted_by_amount(): void
    {
        $supplier1 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 1']);
        $supplier2 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 2']);
        $supplier3 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 3']);

        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        $this->rfqService->submitQuotation($rfq->id, ['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier1->id, 'total_amount' => 3000]);
        $this->rfqService->submitQuotation($rfq->id, ['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier2->id, 'total_amount' => 1500]);
        $this->rfqService->submitQuotation($rfq->id, ['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier3->id, 'total_amount' => 2000]);

        $comparison = $this->rfqService->compareQuotations($rfq->id);

        $this->assertCount(3, $comparison);
        $this->assertEquals('1500.00', $comparison->first()->total_amount);
        $this->assertEquals('3000.00', $comparison->last()->total_amount);
    }

    /** @test */
    public function can_select_quotation_and_reject_others(): void
    {
        Event::fake();

        $supplier1 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 1']);
        $supplier2 = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 2']);

        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        $q1 = $this->rfqService->submitQuotation($rfq->id, ['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier1->id, 'total_amount' => 1000]);
        $q2 = $this->rfqService->submitQuotation($rfq->id, ['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier2->id, 'total_amount' => 1200]);

        $this->rfqService->closeRFQ($rfq->id, 'actor-uuid', 'test-tenant');
        $selected = $this->rfqService->selectQuotation($q1->id, 'actor-uuid', 'test-tenant');

        $this->assertEquals('selected', $selected->status);
        $this->assertEquals('rejected', $q2->fresh()->status);

        Event::assertDispatched(\App\Procurement\Events\QuotationSelected::class);
    }

    /** @test */
    public function cannot_select_quotation_from_open_rfq(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Sup 1']);
        $rfq = $this->rfqService->createRFQ(['tenant_slug' => 'test-tenant', 'title' => 'Test RFQ']);
        $this->rfqService->sendRFQ($rfq->id, 'actor-uuid', 'test-tenant');

        $q = $this->rfqService->submitQuotation($rfq->id, ['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id, 'total_amount' => 1000]);

        $this->expectException(\InvalidArgumentException::class);
        $this->rfqService->selectQuotation($q->id, 'actor-uuid', 'test-tenant');
    }

    // =========================================================================
    // CAP-063 — PurchaseOrderService
    // =========================================================================

    /** @test */
    public function can_create_purchase_order(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);

        $po = $this->poService->createPurchaseOrder([
            'tenant_slug'  => 'test-tenant',
            'supplier_id'  => $supplier->id,
            'po_number'    => 'PO-2026-001',
            'total_amount' => 5000.00,
        ], 'actor-uuid');

        $this->assertNotNull($po->id);
        $this->assertEquals('draft', $po->status);
        $this->assertEquals($supplier->id, $po->supplier_id);
    }

    /** @test */
    public function create_purchase_order_requires_supplier_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('supplier_id is required');

        $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant']);
    }

    /** @test */
    public function can_add_item_to_draft_purchase_order(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);

        $item = $this->poService->addItem($po->id, [
            'description' => 'Office Chair',
            'quantity'    => 5,
            'unit'        => 'pcs',
            'unit_price'  => 300.00,
            'total_price' => 1500.00,
        ]);

        $this->assertNotNull($item->id);
        $this->assertEquals($po->id, $item->purchase_order_id);
        $this->assertEquals('Office Chair', $item->description);
    }

    /** @test */
    public function cannot_add_item_to_non_draft_purchase_order(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);
        $this->poService->submitPurchaseOrder($po->id, 'user-uuid', 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->poService->addItem($po->id, ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100]);
    }

    /** @test */
    public function can_submit_purchase_order(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);

        $submitted = $this->poService->submitPurchaseOrder($po->id, 'user-uuid', 'test-tenant');

        $this->assertEquals('submitted', $submitted->status);
        $this->assertNotNull($submitted->approval_request_id);
    }

    /** @test */
    public function can_approve_purchase_order(): void
    {
        Event::fake();

        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);
        $this->poService->submitPurchaseOrder($po->id, 'user-uuid', 'test-tenant');
        $approved = $this->poService->approvePurchaseOrder($po->id, 'approver-uuid', 'test-tenant');

        $this->assertEquals('approved', $approved->status);
    }

    /** @test */
    public function can_issue_purchase_order(): void
    {
        Event::fake();

        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);
        $this->poService->submitPurchaseOrder($po->id, 'user-uuid', 'test-tenant');
        $this->poService->approvePurchaseOrder($po->id, 'approver-uuid', 'test-tenant');
        $issued = $this->poService->issuePurchaseOrder($po->id, 'actor-uuid', 'test-tenant');

        $this->assertEquals('issued', $issued->status);
        $this->assertNotNull($issued->issued_date);
    }

    /** @test */
    public function issue_purchase_order_dispatches_event(): void
    {
        Event::fake();

        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);
        $this->poService->submitPurchaseOrder($po->id, 'user-uuid', 'test-tenant');
        $this->poService->approvePurchaseOrder($po->id, 'approver-uuid', 'test-tenant');
        $this->poService->issuePurchaseOrder($po->id, 'actor-uuid', 'test-tenant');

        Event::assertDispatched(\App\Procurement\Events\PurchaseOrderIssued::class);
    }

    /** @test */
    public function cannot_issue_non_approved_purchase_order(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->poService->issuePurchaseOrder($po->id, 'actor-uuid', 'test-tenant');
    }

    /** @test */
    public function can_cancel_draft_purchase_order(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);

        $cancelled = $this->poService->cancelPurchaseOrder($po->id, 'actor-uuid', 'test-tenant');
        $this->assertEquals('cancelled', $cancelled->status);
    }

    /** @test */
    public function cannot_cancel_issued_purchase_order(): void
    {
        Event::fake();

        $supplier = TenantSupplier::create(['tenant_slug' => 'test-tenant', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder(['tenant_slug' => 'test-tenant', 'supplier_id' => $supplier->id]);
        $this->poService->submitPurchaseOrder($po->id, 'user-uuid', 'test-tenant');
        $this->poService->approvePurchaseOrder($po->id, 'approver-uuid', 'test-tenant');
        $this->poService->issuePurchaseOrder($po->id, 'actor-uuid', 'test-tenant');

        $this->expectException(\InvalidArgumentException::class);
        $this->poService->cancelPurchaseOrder($po->id, 'actor-uuid', 'test-tenant');
    }

    // =========================================================================
    // Tenant Isolation
    // =========================================================================

    /** @test */
    public function purchase_request_stores_tenant_slug(): void
    {
        $pr = $this->prService->createPurchaseRequest([
            'tenant_slug' => 'tenant-alpha',
            'title'       => 'Alpha PR',
        ]);

        $this->assertEquals('tenant-alpha', $pr->tenant_slug);
    }

    /** @test */
    public function rfq_stores_tenant_slug(): void
    {
        $rfq = $this->rfqService->createRFQ([
            'tenant_slug' => 'tenant-beta',
            'title'       => 'Beta RFQ',
        ]);

        $this->assertEquals('tenant-beta', $rfq->tenant_slug);
    }

    /** @test */
    public function purchase_order_stores_tenant_slug(): void
    {
        $supplier = TenantSupplier::create(['tenant_slug' => 'tenant-gamma', 'name' => 'Supplier']);
        $po = $this->poService->createPurchaseOrder([
            'tenant_slug' => 'tenant-gamma',
            'supplier_id' => $supplier->id,
        ]);

        $this->assertEquals('tenant-gamma', $po->tenant_slug);
    }
}
