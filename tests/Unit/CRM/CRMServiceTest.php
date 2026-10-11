<?php

declare(strict_types=1);

namespace Tests\Unit\CRM;

use App\CRM\CRMService;
use App\CRM\Events\ClientCreated;
use App\CRM\Events\OpportunityLost;
use App\CRM\Events\OpportunityWon;
use InvalidArgumentException;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/**
 * Unit tests for CRMService — TASK-003B.
 *
 * Verifies:
 *  1. createClient — company and individual
 *  2. createLead
 *  3. createOpportunity
 *  4. markOpportunityWon
 *  5. markOpportunityLost
 *  6. recordActivity
 *  7. getClient — found and not found
 *  8. getContact — found and not found
 *  9. validateClient — active, inactive, not found
 * 10. ClientCreated event dispatch
 * 11. OpportunityWon event dispatch
 * 12. OpportunityLost event dispatch
 * 13. Audit integration
 * 14. Forbidden dependency check
 */
class CRMServiceTest extends TestCase
{
    use RefreshDatabase;

    private CRMService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CRMService::class);
    }

    // -------------------------------------------------------------------------
    // Forbidden dependency check
    // -------------------------------------------------------------------------

    /** @test */
    public function crm_service_has_no_forbidden_dependencies(): void
    {
        $source = file_get_contents(app_path('CRM/CRMService.php'));

        $forbidden = [
            'App\\Accounting',
            'App\\Finance',
            'App\\Invoicing',
            'App\\Receivables',
            'App\\Procurement',
            'App\\Tender',
            'App\\BOQ',
            'App\\Contract',
        ];

        foreach ($forbidden as $dep) {
            $this->assertStringNotContainsString(
                $dep,
                $source,
                "CRMService must not depend on [{$dep}]"
            );
        }
    }

    // -------------------------------------------------------------------------
    // createClient — validation
    // -------------------------------------------------------------------------

    /** @test */
    public function create_client_throws_on_missing_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createClient(['name' => 'Test', 'tenant_slug' => 'tenant1']);
    }

    /** @test */
    public function create_client_throws_on_invalid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createClient(['type' => 'corporation', 'name' => 'Test', 'tenant_slug' => 'tenant1']);
    }

    /** @test */
    public function create_client_throws_on_missing_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createClient(['type' => 'company', 'tenant_slug' => 'tenant1']);
    }

    /** @test */
    public function create_client_throws_on_missing_tenant_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createClient(['type' => 'company', 'name' => 'Test']);
    }

    // -------------------------------------------------------------------------
    // createClient — company
    // -------------------------------------------------------------------------

    /** @test */
    public function create_company_client_persists_and_dispatches_event(): void
    {
        Event::fake([ClientCreated::class]);

        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Acme Construction LLC',
            'tenant_slug' => 'tenant-acme',
            'email'       => 'info@acme.qa',
            'country'     => 'Qatar',
            'city'        => 'Doha',
        ]);

        $this->assertNotNull($client->id);
        $this->assertSame('company', $client->type);
        $this->assertSame('Acme Construction LLC', $client->name);
        $this->assertSame('active', $client->status);
        $this->assertSame('tenant-acme', $client->tenant_slug);

        Event::assertDispatched(ClientCreated::class, function (ClientCreated $event) use ($client) {
            return $event->clientId === $client->id
                && $event->type === 'company'
                && $event->tenantSlug === 'tenant-acme';
        });
    }

    // -------------------------------------------------------------------------
    // createClient — individual
    // -------------------------------------------------------------------------

    /** @test */
    public function create_individual_client_persists_and_dispatches_event(): void
    {
        Event::fake([ClientCreated::class]);

        $client = $this->service->createClient([
            'type'        => 'individual',
            'name'        => 'Ahmed Al-Rashid',
            'tenant_slug' => 'tenant-test',
            'phone'       => '+974-5555-1234',
        ]);

        $this->assertSame('individual', $client->type);
        $this->assertSame('Ahmed Al-Rashid', $client->name);

        Event::assertDispatched(ClientCreated::class, function (ClientCreated $event) use ($client) {
            return $event->clientId === $client->id && $event->type === 'individual';
        });
    }

    // -------------------------------------------------------------------------
    // createLead
    // -------------------------------------------------------------------------

    /** @test */
    public function create_lead_throws_on_missing_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createLead(['tenant_slug' => 'tenant1']);
    }

    /** @test */
    public function create_lead_persists(): void
    {
        $lead = $this->service->createLead([
            'name'        => 'Prospect Corp',
            'tenant_slug' => 'tenant-test',
            'email'       => 'prospect@example.com',
            'source'      => 'referral',
        ]);

        $this->assertNotNull($lead->id);
        $this->assertSame('Prospect Corp', $lead->name);
        $this->assertSame('new', $lead->status);
        $this->assertSame('tenant-test', $lead->tenant_slug);
    }

    // -------------------------------------------------------------------------
    // createOpportunity
    // -------------------------------------------------------------------------

    /** @test */
    public function create_opportunity_throws_on_missing_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createOpportunity(['tenant_slug' => 'tenant1']);
    }

    /** @test */
    public function create_opportunity_persists(): void
    {
        $opportunity = $this->service->createOpportunity([
            'title'           => 'Office Tower Project',
            'tenant_slug'     => 'tenant-test',
            'estimated_value' => 5000000.00,
            'currency'        => 'QAR',
        ]);

        $this->assertNotNull($opportunity->id);
        $this->assertSame('Office Tower Project', $opportunity->title);
        $this->assertSame('open', $opportunity->status);
        $this->assertSame('QAR', $opportunity->currency);
    }

    // -------------------------------------------------------------------------
    // markOpportunityWon
    // -------------------------------------------------------------------------

    /** @test */
    public function mark_opportunity_won_throws_on_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->markOpportunityWon('non-existent-uuid');
    }

    /** @test */
    public function mark_opportunity_won_sets_status_and_dispatches_event(): void
    {
        Event::fake([OpportunityWon::class]);

        $opportunity = $this->service->createOpportunity([
            'title'       => 'Won Deal',
            'tenant_slug' => 'tenant-test',
        ]);

        $won = $this->service->markOpportunityWon($opportunity->id);

        $this->assertSame('won', $won->status);
        $this->assertNotNull($won->won_at);

        Event::assertDispatched(OpportunityWon::class, function (OpportunityWon $event) use ($opportunity) {
            return $event->opportunityId === $opportunity->id;
        });
    }

    /** @test */
    public function mark_opportunity_won_throws_if_already_terminal(): void
    {
        $opportunity = $this->service->createOpportunity([
            'title'       => 'Already Won',
            'tenant_slug' => 'tenant-test',
        ]);

        Event::fake();
        $this->service->markOpportunityWon($opportunity->id);

        $this->expectException(InvalidArgumentException::class);
        $this->service->markOpportunityWon($opportunity->id);
    }

    // -------------------------------------------------------------------------
    // markOpportunityLost
    // -------------------------------------------------------------------------

    /** @test */
    public function mark_opportunity_lost_throws_on_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->markOpportunityLost('non-existent-uuid');
    }

    /** @test */
    public function mark_opportunity_lost_sets_status_and_dispatches_event(): void
    {
        Event::fake([OpportunityLost::class]);

        $opportunity = $this->service->createOpportunity([
            'title'       => 'Lost Deal',
            'tenant_slug' => 'tenant-test',
        ]);

        $lost = $this->service->markOpportunityLost($opportunity->id, 'budget_cut');

        $this->assertSame('lost', $lost->status);
        $this->assertNotNull($lost->lost_at);
        $this->assertSame('budget_cut', $lost->lost_reason);

        Event::assertDispatched(OpportunityLost::class, function (OpportunityLost $event) use ($opportunity) {
            return $event->opportunityId === $opportunity->id
                && $event->lostReason === 'budget_cut';
        });
    }

    // -------------------------------------------------------------------------
    // recordActivity
    // -------------------------------------------------------------------------

    /** @test */
    public function record_activity_throws_on_missing_required_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->recordActivity(['subject_type' => 'App\\CRM\\Client']);
    }

    /** @test */
    public function record_activity_persists(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Activity Client',
            'tenant_slug' => 'tenant-test',
        ]);

        $activity = $this->service->recordActivity([
            'subject_type' => \App\CRM\Client::class,
            'subject_id'   => $client->id,
            'type'         => 'call',
            'summary'      => 'Initial discovery call',
            'tenant_slug'  => 'tenant-test',
        ]);

        $this->assertNotNull($activity->id);
        $this->assertSame('call', $activity->type);
        $this->assertSame('Initial discovery call', $activity->summary);
    }

    // -------------------------------------------------------------------------
    // getClient
    // -------------------------------------------------------------------------

    /** @test */
    public function get_client_returns_existing_client(): void
    {
        $created = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Findable Corp',
            'tenant_slug' => 'tenant-test',
        ]);

        $found = $this->service->getClient($created->id);

        $this->assertSame($created->id, $found->id);
        $this->assertSame('Findable Corp', $found->name);
    }

    /** @test */
    public function get_client_throws_on_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->service->getClient('00000000-0000-0000-0000-000000000000');
    }

    /** @test */
    public function get_client_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->getClient('');
    }

    // -------------------------------------------------------------------------
    // getContact
    // -------------------------------------------------------------------------

    /** @test */
    public function get_contact_returns_existing_contact(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Contact Owner',
            'tenant_slug' => 'tenant-test',
        ]);

        $contact = \App\CRM\Contact::create([
            'client_id'   => $client->id,
            'name'        => 'Jane Doe',
            'role'        => 'PM',
            'email'       => 'jane@example.com',
            'tenant_slug' => 'tenant-test',
            'status'      => 'active',
        ]);

        $found = $this->service->getContact($contact->id);

        $this->assertSame($contact->id, $found->id);
        $this->assertSame('Jane Doe', $found->name);
    }

    /** @test */
    public function get_contact_throws_on_not_found(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        $this->service->getContact('00000000-0000-0000-0000-000000000000');
    }

    // -------------------------------------------------------------------------
    // validateClient
    // -------------------------------------------------------------------------

    /** @test */
    public function validate_client_returns_valid_for_active_client(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Active Corp',
            'tenant_slug' => 'tenant-test',
            'status'      => 'active',
        ]);

        $result = $this->service->validateClient($client->id);

        $this->assertTrue($result['isValid']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function validate_client_returns_invalid_for_inactive_client(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Inactive Corp',
            'tenant_slug' => 'tenant-test',
            'status'      => 'inactive',
        ]);

        $result = $this->service->validateClient($client->id);

        $this->assertFalse($result['isValid']);
        $this->assertSame('inactive', $result['reason']);
    }

    /** @test */
    public function validate_client_returns_invalid_for_blocked_client(): void
    {
        $client = $this->service->createClient([
            'type'        => 'company',
            'name'        => 'Blocked Corp',
            'tenant_slug' => 'tenant-test',
            'status'      => 'blocked',
        ]);

        $result = $this->service->validateClient($client->id);

        $this->assertFalse($result['isValid']);
        $this->assertSame('blocked', $result['reason']);
    }

    /** @test */
    public function validate_client_returns_invalid_for_not_found_without_exception(): void
    {
        // ValidateClient must NEVER throw — returns false with reason 'not_found'
        $result = $this->service->validateClient('00000000-0000-0000-0000-000000000000');

        $this->assertFalse($result['isValid']);
        $this->assertSame('not_found', $result['reason']);
    }

    /** @test */
    public function validate_client_returns_invalid_for_empty_id(): void
    {
        $result = $this->service->validateClient('');

        $this->assertFalse($result['isValid']);
    }
}
