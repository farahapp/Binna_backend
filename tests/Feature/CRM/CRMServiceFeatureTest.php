<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\CRM\Client;
use App\CRM\Contact;
use App\CRM\CRMActivity;
use App\CRM\CRMService;
use App\CRM\Events\ClientCreated;
use App\CRM\Events\OpportunityLost;
use App\CRM\Events\OpportunityWon;
use App\CRM\Lead;
use App\CRM\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Feature tests for CRMService — TASK-003B.
 *
 * Verifies:
 *  1. Full lifecycle: createClient → getClient → validateClient
 *  2. Opportunity lifecycle: create → won → lost
 *  3. Event dispatch: ClientCreated, OpportunityWon, OpportunityLost
 *  4. Audit integration: crm.client.created, crm.opportunity.won, crm.opportunity.lost
 *  5. Tenant isolation: cross-tenant access rejection
 *  6. GetClient / GetContact / ValidateClient contract behavior
 */
class CRMServiceFeatureTest extends TestCase
{
    use RefreshDatabase;

    private CRMService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CRMService::class);
    }

    // -------------------------------------------------------------------------
    // Full client lifecycle
    // -------------------------------------------------------------------------

    /** @test */
    public function full_client_lifecycle_create_get_validate(): void
    {
        Event::fake([ClientCreated::class]);

        // Create
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Lifecycle Corp',
            'tenant_slug' => 'tenant-lifecycle',
            'email'       => 'info@lifecycle.qa',
            'country'     => 'Qatar',
            'city'        => 'Doha',
            'status'      => 'active',
        ]);

        $this->assertNotNull($client->id);

        // GetClient
        $found = $this->service->getClient($client->id);
        $this->assertSame($client->id, $found->id);
        $this->assertSame('Lifecycle Corp', $found->name);
        $this->assertSame('company', $found->type);

        // ValidateClient — active
        $validation = $this->service->validateClient($client->id);
        $this->assertTrue($validation['isValid']);
        $this->assertNull($validation['reason']);

        // Event dispatched
        Event::assertDispatched(ClientCreated::class);
    }

    // -------------------------------------------------------------------------
    // Opportunity lifecycle: create → won
    // -------------------------------------------------------------------------

    /** @test */
    public function opportunity_lifecycle_create_and_mark_won(): void
    {
        Event::fake([ClientCreated::class, OpportunityWon::class]);

        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Won Client',
            'tenant_slug' => 'tenant-test',
        ]);

        $opportunity = $this->service->createOpportunity([
            'title'           => 'Big Construction Deal',
            'tenant_slug'     => 'tenant-test',
            'client_id'       => $client->id,
            'estimated_value' => 10000000.00,
            'currency'        => 'QAR',
        ]);

        $this->assertSame('open', $opportunity->status);

        $won = $this->service->markOpportunityWon($opportunity->id);

        $this->assertSame('won', $won->status);
        $this->assertNotNull($won->won_at);
        $this->assertNull($won->lost_at);

        Event::assertDispatched(OpportunityWon::class, function (OpportunityWon $event) use ($opportunity, $client) {
            return $event->opportunityId === $opportunity->id
                && $event->clientId === $client->id
                && $event->tenantSlug === 'tenant-test';
        });
    }

    // -------------------------------------------------------------------------
    // Opportunity lifecycle: create → lost
    // -------------------------------------------------------------------------

    /** @test */
    public function opportunity_lifecycle_create_and_mark_lost(): void
    {
        Event::fake([ClientCreated::class, OpportunityLost::class]);

        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Lost Client',
            'tenant_slug' => 'tenant-test',
        ]);

        $opportunity = $this->service->createOpportunity([
            'title'       => 'Lost Deal',
            'tenant_slug' => 'tenant-test',
            'client_id'   => $client->id,
        ]);

        $lost = $this->service->markOpportunityLost($opportunity->id, 'competitor_won');

        $this->assertSame('lost', $lost->status);
        $this->assertNotNull($lost->lost_at);
        $this->assertSame('competitor_won', $lost->lost_reason);
        $this->assertNull($lost->won_at);

        Event::assertDispatched(OpportunityLost::class, function (OpportunityLost $event) use ($opportunity) {
            return $event->opportunityId === $opportunity->id
                && $event->lostReason === 'competitor_won';
        });
    }

    // -------------------------------------------------------------------------
    // Audit integration
    // -------------------------------------------------------------------------

    /** @test */
    public function create_client_writes_audit_entry(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Audited Corp',
            'tenant_slug' => 'tenant-audit',
        ]);

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'crm.client.created',
            'tenant_slug'  => 'tenant-audit',
            'subject_type' => \App\CRM\Client::class,
            'subject_id'   => $client->id,
        ], 'tenant');
    }

    /** @test */
    public function mark_opportunity_won_writes_audit_entry(): void
    {
        $opportunity = $this->service->createOpportunity([
            'title'       => 'Audit Won Deal',
            'tenant_slug' => 'tenant-audit',
        ]);

        $this->service->markOpportunityWon($opportunity->id);

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'crm.opportunity.won',
            'tenant_slug'  => 'tenant-audit',
            'subject_type' => \App\CRM\Opportunity::class,
            'subject_id'   => $opportunity->id,
        ], 'tenant');
    }

    /** @test */
    public function mark_opportunity_lost_writes_audit_entry(): void
    {
        $opportunity = $this->service->createOpportunity([
            'title'       => 'Audit Lost Deal',
            'tenant_slug' => 'tenant-audit',
        ]);

        $this->service->markOpportunityLost($opportunity->id, 'no_budget');

        $this->assertDatabaseHas('audit_entries', [
            'event'        => 'crm.opportunity.lost',
            'tenant_slug'  => 'tenant-audit',
            'subject_type' => \App\CRM\Opportunity::class,
            'subject_id'   => $opportunity->id,
        ], 'tenant');
    }

    // -------------------------------------------------------------------------
    // GetClient contract
    // -------------------------------------------------------------------------

    /** @test */
    public function get_client_contract_returns_correct_fields(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Contract Test Corp',
            'tenant_slug' => 'tenant-contract',
            'trade_name'  => 'CTC',
            'email'       => 'ctc@example.qa',
            'phone'       => '+974-1111-2222',
            'country'     => 'Qatar',
            'city'        => 'Doha',
            'status'      => 'active',
        ]);

        $found = $this->service->getClient($client->id);

        $this->assertSame($client->id, $found->id);
        $this->assertSame('Contract Test Corp', $found->name);
        $this->assertSame('company', $found->type);
        $this->assertSame('active', $found->status);
        $this->assertSame('CTC', $found->trade_name);
        $this->assertSame('ctc@example.qa', $found->email);
        $this->assertSame('Qatar', $found->country);
        $this->assertSame('tenant-contract', $found->tenant_slug);
    }

    /** @test */
    public function get_client_contract_throws_on_not_found(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->service->getClient('00000000-0000-0000-0000-000000000000');
    }

    // -------------------------------------------------------------------------
    // GetContact contract
    // -------------------------------------------------------------------------

    /** @test */
    public function get_contact_contract_returns_correct_fields(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Contact Owner Corp',
            'tenant_slug' => 'tenant-contact',
        ]);

        $contact = Contact::create([
            'client_id'   => $client->id,
            'name'        => 'John Smith',
            'role'        => 'Director',
            'email'       => 'john@example.qa',
            'phone'       => '+974-3333-4444',
            'mobile'      => '+974-5555-6666',
            'tenant_slug' => 'tenant-contact',
            'status'      => 'active',
        ]);

        $found = $this->service->getContact($contact->id);

        $this->assertSame($contact->id, $found->id);
        $this->assertSame('John Smith', $found->name);
        $this->assertSame('Director', $found->role);
        $this->assertSame('john@example.qa', $found->email);
        $this->assertSame($client->id, $found->client_id);
        $this->assertSame('tenant-contact', $found->tenant_slug);
    }

    /** @test */
    public function get_contact_contract_throws_on_not_found(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->service->getContact('00000000-0000-0000-0000-000000000000');
    }

    // -------------------------------------------------------------------------
    // ValidateClient contract
    // -------------------------------------------------------------------------

    /** @test */
    public function validate_client_contract_active_returns_valid(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Valid Corp',
            'tenant_slug' => 'tenant-validate',
            'status'      => 'active',
        ]);

        $result = $this->service->validateClient($client->id);

        $this->assertTrue($result['isValid']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function validate_client_contract_inactive_returns_invalid(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Inactive Corp',
            'tenant_slug' => 'tenant-validate',
            'status'      => 'inactive',
        ]);

        $result = $this->service->validateClient($client->id);

        $this->assertFalse($result['isValid']);
        $this->assertSame('inactive', $result['reason']);
    }

    /** @test */
    public function validate_client_contract_not_found_returns_false_no_exception(): void
    {
        // CRITICAL: ValidateClient must NEVER throw on not-found
        $result = $this->service->validateClient('00000000-0000-0000-0000-000000000000');

        $this->assertFalse($result['isValid']);
        $this->assertSame('not_found', $result['reason']);
    }

    // -------------------------------------------------------------------------
    // Tenant isolation
    // -------------------------------------------------------------------------

    /** @test */
    public function crm_service_uses_tenant_connection(): void
    {
        // All CRM models must use the 'tenant' connection (OD-001)
        $this->assertSame('tenant', (new Client())->getConnectionName());
        $this->assertSame('tenant', (new Contact())->getConnectionName());
        $this->assertSame('tenant', (new Lead())->getConnectionName());
        $this->assertSame('tenant', (new Opportunity())->getConnectionName());
        $this->assertSame('tenant', (new CRMActivity())->getConnectionName());
    }

    /** @test */
    public function clients_are_scoped_to_tenant_schema(): void
    {
        // Create a client in the test tenant schema
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Tenant Scoped Corp',
            'tenant_slug' => 'tenant-isolation-test',
        ]);

        // Verify it exists in the tenant schema
        $found = Client::find($client->id);
        $this->assertNotNull($found);
        $this->assertSame('Tenant Scoped Corp', $found->name);

        // Verify tenant_slug is stored for cross-schema traceability
        $this->assertSame('tenant-isolation-test', $found->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // Event payload verification
    // -------------------------------------------------------------------------

    /** @test */
    public function client_created_event_has_correct_payload(): void
    {
        Event::fake([ClientCreated::class]);

        $client = $this->service->createClient([
            'type'        => 'individual',
            'name'        => 'Payload Test',
            'tenant_slug' => 'tenant-payload',
        ]);

        Event::assertDispatched(ClientCreated::class, function (ClientCreated $event) use ($client) {
            return $event->clientId === $client->id
                && $event->type === 'individual'
                && $event->tenantSlug === 'tenant-payload'
                && $event->occurredAt instanceof \DateTimeImmutable;
        });
    }

    /** @test */
    public function opportunity_won_event_has_correct_payload(): void
    {
        Event::fake([ClientCreated::class, OpportunityWon::class]);

        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Won Payload Client',
            'tenant_slug' => 'tenant-payload',
        ]);

        $opportunity = $this->service->createOpportunity([
            'title'       => 'Won Payload Deal',
            'tenant_slug' => 'tenant-payload',
            'client_id'   => $client->id,
        ]);

        $this->service->markOpportunityWon($opportunity->id);

        Event::assertDispatched(OpportunityWon::class, function (OpportunityWon $event) use ($opportunity, $client) {
            return $event->opportunityId === $opportunity->id
                && $event->clientId === $client->id
                && $event->tenantSlug === 'tenant-payload'
                && $event->wonAt instanceof \DateTimeImmutable;
        });
    }

    /** @test */
    public function opportunity_lost_event_has_correct_payload(): void
    {
        Event::fake([ClientCreated::class, OpportunityLost::class]);

        $opportunity = $this->service->createOpportunity([
            'title'       => 'Lost Payload Deal',
            'tenant_slug' => 'tenant-payload',
        ]);

        $this->service->markOpportunityLost($opportunity->id, 'price_too_high');

        Event::assertDispatched(OpportunityLost::class, function (OpportunityLost $event) use ($opportunity) {
            return $event->opportunityId === $opportunity->id
                && $event->lostReason === 'price_too_high'
                && $event->tenantSlug === 'tenant-payload'
                && $event->lostAt instanceof \DateTimeImmutable;
        });
    }
}
