<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\CRM\Client;
use App\CRM\Contact;
use App\CRM\CRMActivity;
use App\CRM\Lead;
use App\CRM\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feature tests for CRM Domain/Data Foundation — TASK-003A.
 *
 * Verifies:
 *  1. Client persistence (company and individual)
 *  2. Contact persistence and Client relationship
 *  3. Lead/Prospect persistence
 *  4. Opportunity persistence and lifecycle fields
 *  5. CRM Activity persistence
 *  6. Migration/schema correctness
 *  7. All CRM tables exist in tenant schema
 *
 * Tenant isolation is verified in CRMTenantIsolationTest.
 */
class CRMFoundationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Schema / Migration Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function clients_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('clients'),
            'clients table must exist in the tenant schema'
        );
    }

    /** @test */
    public function contacts_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('contacts'),
            'contacts table must exist in the tenant schema'
        );
    }

    /** @test */
    public function leads_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('leads'),
            'leads table must exist in the tenant schema'
        );
    }

    /** @test */
    public function opportunities_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('opportunities'),
            'opportunities table must exist in the tenant schema'
        );
    }

    /** @test */
    public function crm_activities_table_exists_in_tenant_schema(): void
    {
        $this->assertTrue(
            Schema::connection('tenant')->hasTable('crm_activities'),
            'crm_activities table must exist in the tenant schema'
        );
    }

    /** @test */
    public function crm_migrations_are_in_correct_path(): void
    {
        $this->assertFileExists(
            database_path('migrations/crm/2026_09_27_000001_create_clients_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/crm/2026_09_27_000002_create_contacts_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/crm/2026_09_27_000003_create_leads_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/crm/2026_09_27_000004_create_opportunities_table.php')
        );
        $this->assertFileExists(
            database_path('migrations/crm/2026_09_27_000005_create_crm_activities_table.php')
        );
    }

    /** @test */
    public function crm_migrations_are_not_in_shared_path(): void
    {
        $this->assertFileDoesNotExist(
            database_path('migrations/shared/2026_09_27_000001_create_clients_table.php'),
            'CRM migrations must NOT be in the shared migration path'
        );
    }

    // -------------------------------------------------------------------------
    // Client Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function company_client_can_be_persisted(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Acme Construction LLC',
            'trade_name'  => 'Acme',
            'email'       => 'info@acme.qa',
            'phone'       => '+974-1234-5678',
            'country'     => 'Qatar',
            'city'        => 'Doha',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($client->id);
        $this->assertSame('company', $client->type);
        $this->assertSame('Acme Construction LLC', $client->name);
        $this->assertTrue($client->isCompany());
        $this->assertTrue($client->isActive());
    }

    /** @test */
    public function individual_client_can_be_persisted(): void
    {
        $client = Client::create([
            'type'        => 'individual',
            'name'        => 'Mohammed Al-Rashid',
            'email'       => 'm.rashid@example.qa',
            'phone'       => '+974-9876-5432',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($client->id);
        $this->assertSame('individual', $client->type);
        $this->assertTrue($client->isIndividual());
        $this->assertFalse($client->isCompany());
    }

    /** @test */
    public function client_status_defaults_to_active(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Default Status Co',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertSame('active', $client->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Contact Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function contact_can_be_persisted_with_client_relationship(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Test Company',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $contact = Contact::create([
            'client_id'   => $client->id,
            'name'        => 'Ahmed Al-Farsi',
            'role'        => 'Project Manager',
            'email'       => 'ahmed@testco.qa',
            'phone'       => '+974-1111-2222',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($contact->id);
        $this->assertSame($client->id, $contact->client_id);
        $this->assertSame('Ahmed Al-Farsi', $contact->name);
        $this->assertSame('Project Manager', $contact->role);

        // Verify relationship
        $this->assertSame($client->id, $contact->client->id);
    }

    /** @test */
    public function contact_can_be_persisted_without_client(): void
    {
        // client_id is nullable — Contact may exist before Client linkage
        $contact = Contact::create([
            'client_id'   => null,
            'name'        => 'Standalone Contact',
            'email'       => 'standalone@example.qa',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($contact->id);
        $this->assertNull($contact->client_id);
    }

    // -------------------------------------------------------------------------
    // Lead Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function lead_can_be_persisted(): void
    {
        $lead = Lead::create([
            'name'        => 'Prospect Corp',
            'email'       => 'prospect@example.qa',
            'phone'       => '+974-5555-6666',
            'company'     => 'Prospect Corp',
            'source'      => 'referral',
            'status'      => 'new',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertNotNull($lead->id);
        $this->assertSame('new', $lead->status);
        $this->assertTrue($lead->isNew());
        $this->assertFalse($lead->isConverted());
    }

    /** @test */
    public function lead_can_be_marked_as_converted_with_client_reference(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Converted Client',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $lead = Lead::create([
            'name'                => 'Future Client',
            'status'              => 'converted',
            'converted_client_id' => $client->id,
            'converted_at'        => now(),
            'tenant_slug'         => 'test-tenant',
        ]);

        $this->assertTrue($lead->isConverted());
        $this->assertSame($client->id, $lead->converted_client_id);
        $this->assertNotNull($lead->converted_at);
    }

    // -------------------------------------------------------------------------
    // Opportunity Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function opportunity_can_be_persisted(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Opportunity Client',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $opportunity = Opportunity::create([
            'client_id'            => $client->id,
            'title'                => 'Office Building Construction',
            'description'          => 'New office complex in West Bay',
            'estimated_value'      => 5000000.00,
            'currency'             => 'QAR',
            'status'               => 'open',
            'expected_close_date'  => now()->addMonths(3)->toDateString(),
            'tenant_slug'          => 'test-tenant',
        ]);

        $this->assertNotNull($opportunity->id);
        $this->assertSame('open', $opportunity->status);
        $this->assertTrue($opportunity->isOpen());
        $this->assertFalse($opportunity->isTerminal());
    }

    /** @test */
    public function opportunity_lifecycle_supports_won_status(): void
    {
        $opportunity = Opportunity::create([
            'title'       => 'Won Deal',
            'status'      => 'won',
            'won_at'      => now(),
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertTrue($opportunity->isWon());
        $this->assertTrue($opportunity->isTerminal());
        $this->assertNotNull($opportunity->won_at);
    }

    /** @test */
    public function opportunity_lifecycle_supports_lost_status(): void
    {
        $opportunity = Opportunity::create([
            'title'       => 'Lost Deal',
            'status'      => 'lost',
            'lost_at'     => now(),
            'lost_reason' => 'Budget constraints',
            'tenant_slug' => 'test-tenant',
        ]);

        $this->assertTrue($opportunity->isLost());
        $this->assertTrue($opportunity->isTerminal());
        $this->assertNotNull($opportunity->lost_at);
        $this->assertSame('Budget constraints', $opportunity->lost_reason);
    }

    // -------------------------------------------------------------------------
    // CRM Activity Persistence Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function crm_activity_can_be_persisted_for_client(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Activity Client',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        $activity = CRMActivity::create([
            'subject_type' => Client::class,
            'subject_id'   => $client->id,
            'type'         => 'call',
            'summary'      => 'Initial discovery call',
            'notes'        => 'Discussed project requirements',
            'occurred_at'  => now(),
            'tenant_slug'  => 'test-tenant',
        ]);

        $this->assertNotNull($activity->id);
        $this->assertSame(Client::class, $activity->subject_type);
        $this->assertSame($client->id, $activity->subject_id);
        $this->assertSame('call', $activity->type);
    }

    /** @test */
    public function crm_activity_can_be_persisted_for_opportunity(): void
    {
        $opportunity = Opportunity::create([
            'title'       => 'Activity Opportunity',
            'status'      => 'open',
            'tenant_slug' => 'test-tenant',
        ]);

        $activity = CRMActivity::create([
            'subject_type' => Opportunity::class,
            'subject_id'   => $opportunity->id,
            'type'         => 'meeting',
            'summary'      => 'Proposal presentation',
            'occurred_at'  => now(),
            'tenant_slug'  => 'test-tenant',
        ]);

        $this->assertNotNull($activity->id);
        $this->assertSame(Opportunity::class, $activity->subject_type);
    }

    // -------------------------------------------------------------------------
    // Relationship Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function client_has_many_contacts(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Multi-Contact Client',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        Contact::create(['client_id' => $client->id, 'name' => 'Contact One', 'tenant_slug' => 'test-tenant']);
        Contact::create(['client_id' => $client->id, 'name' => 'Contact Two', 'tenant_slug' => 'test-tenant']);

        $this->assertCount(2, $client->contacts);
    }

    /** @test */
    public function client_has_many_opportunities(): void
    {
        $client = Client::create([
            'type'        => 'company',
            'name'        => 'Multi-Opp Client',
            'status'      => 'active',
            'tenant_slug' => 'test-tenant',
        ]);

        Opportunity::create(['client_id' => $client->id, 'title' => 'Opp One', 'status' => 'open', 'tenant_slug' => 'test-tenant']);
        Opportunity::create(['client_id' => $client->id, 'title' => 'Opp Two', 'status' => 'open', 'tenant_slug' => 'test-tenant']);

        $this->assertCount(2, $client->opportunities);
    }
}
