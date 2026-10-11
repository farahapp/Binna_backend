<?php

declare(strict_types=1);

namespace App\CRM;

use App\Audit\AuditService;
use App\CRM\Events\ClientCreated;
use App\CRM\Events\OpportunityLost;
use App\CRM\Events\OpportunityWon;
use Illuminate\Support\Facades\Event;

/**
 * CRMService — CRM Application/Service Layer.
 *
 * Architectural constraints (A1 MOD-04 / A2 §2.4 / A4 §4.1 / OD-001):
 *  - CRM is the single owner of Client master data.
 *  - Operates within the active per-tenant schema (OD-001).
 *  - Does NOT accept tenantSlug as a bypass parameter — tenant context
 *    is resolved by the existing Foundation/TenantContext mechanism.
 *  - Dispatches domain events: ClientCreated, OpportunityWon, OpportunityLost.
 *  - Integrates with AuditService for required operations.
 *
 * Forbidden dependencies (A1 MOD-04):
 *  - MUST NOT depend on Accounting, Finance, Invoicing, Receivables,
 *    Procurement, Tender, BOQ, or Contract management.
 *
 * Public Contracts (A4 §4.1):
 *  - getClient(clientId)    → GetClient
 *  - getContact(contactId)  → GetContact
 *  - validateClient(clientId) → ValidateClient
 *
 * TASK-003B — CRM Application/Service Layer
 */
class CRMService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // =========================================================================
    // PUBLIC CONTRACTS (A4 §4.1)
    // =========================================================================

    /**
     * GetClient — retrieve a Client by ID.
     *
     * QUERY contract (A4 §4.1):
     *  - Input:  clientId (UUID string)
     *  - Output: Client model (name, type, status, trade_name, email, phone,
     *            country, city, tenant_slug)
     *  - Failure: throws InvalidArgumentException if not found.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @throws \InvalidArgumentException If the client does not exist.
     */
    public function getClient(string $clientId): Client
    {
        if (empty($clientId)) {
            throw new \InvalidArgumentException('clientId must not be empty.');
        }

        $client = Client::find($clientId);

        if ($client === null) {
            throw new \InvalidArgumentException("Client [{$clientId}] not found.");
        }

        return $client;
    }

    /**
     * GetContact — retrieve a Contact by ID.
     *
     * QUERY contract (A4 §4.1):
     *  - Input:  contactId (UUID string)
     *  - Output: Contact model (name, role, email, phone, mobile, client_id,
     *            tenant_slug)
     *  - Failure: throws InvalidArgumentException if not found.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @throws \InvalidArgumentException If the contact does not exist.
     */
    public function getContact(string $contactId): Contact
    {
        if (empty($contactId)) {
            throw new \InvalidArgumentException('contactId must not be empty.');
        }

        $contact = Contact::find($contactId);

        if ($contact === null) {
            throw new \InvalidArgumentException("Contact [{$contactId}] not found.");
        }

        return $contact;
    }

    /**
     * ValidateClient — check if a Client is active and valid for business transactions.
     *
     * POLICY contract (A4 §4.1):
     *  - Input:  clientId (UUID string)
     *  - Output: array{isValid: bool, reason: string|null}
     *  - Failure: NEVER throws — returns {isValid: false, reason: 'not_found'} if not found.
     *  - Tenant: resolved via active 'tenant' connection (OD-001).
     *  - No side effects.
     *
     * @return array{isValid: bool, reason: string|null}
     */
    public function validateClient(string $clientId): array
    {
        if (empty($clientId)) {
            return ['isValid' => false, 'reason' => 'client_id_empty'];
        }

        $client = Client::find($clientId);

        if ($client === null) {
            return ['isValid' => false, 'reason' => 'not_found'];
        }

        if ($client->status === 'active') {
            return ['isValid' => true, 'reason' => null];
        }

        return ['isValid' => false, 'reason' => $client->status];
    }

    // =========================================================================
    // CRM OPERATIONS (A1 MOD-04)
    // =========================================================================

    /**
     * Create a new Client (company or individual).
     *
     * Dispatches: ClientCreated event (A1 MOD-04).
     * Audit: crm.client.created (NEW-003).
     *
     * @param array{
     *     type:                 string,
     *     name:                 string,
     *     tenant_slug:          string,
     *     trade_name?:          string|null,
     *     registration_number?: string|null,
     *     email?:               string|null,
     *     phone?:               string|null,
     *     address?:             string|null,
     *     country?:             string|null,
     *     city?:                string|null,
     *     status?:              string,
     *     notes?:               string|null,
     * } $attributes
     *
     * @throws \InvalidArgumentException If required fields are missing or invalid.
     */
    public function createClient(array $attributes, string|null $actorId = null): Client
    {
        $this->validateClientAttributes($attributes);

        $client = Client::create([
            'type'                => $attributes['type'],
            'name'                => $attributes['name'],
            'tenant_slug'         => $attributes['tenant_slug'],
            'trade_name'          => $attributes['trade_name']          ?? null,
            'registration_number' => $attributes['registration_number'] ?? null,
            'email'               => $attributes['email']               ?? null,
            'phone'               => $attributes['phone']               ?? null,
            'address'             => $attributes['address']             ?? null,
            'country'             => $attributes['country']             ?? null,
            'city'                => $attributes['city']                ?? null,
            'status'              => $attributes['status']              ?? 'active',
            'notes'               => $attributes['notes']               ?? null,
        ]);

        // Audit: crm.client.created (NEW-003 / TASK-001C)
        $this->auditService->logEvent(
            'crm.client.created',
            $actorId,
            [
                'tenant_slug'  => $client->tenant_slug,
                'subject_type' => Client::class,
                'subject_id'   => $client->id,
                'after'        => ['type' => $client->type, 'name' => $client->name, 'status' => $client->status],
            ]
        );

        // Domain event: ClientCreated (A1 MOD-04)
        // Consumer: SPECIFICATION GAP — dispatch required per MEP.
        Event::dispatch(new ClientCreated(
            clientId:   $client->id,
            type:       $client->type,
            tenantSlug: $client->tenant_slug,
            occurredAt: new \DateTimeImmutable(),
        ));

        return $client;
    }

    /**
     * Create a new Lead/Prospect.
     *
     * @param array{
     *     name:         string,
     *     tenant_slug:  string,
     *     email?:       string|null,
     *     phone?:       string|null,
     *     company?:     string|null,
     *     source?:      string|null,
     *     status?:      string,
     *     notes?:       string|null,
     * } $attributes
     *
     * @throws \InvalidArgumentException If required fields are missing.
     */
    public function createLead(array $attributes): Lead
    {
        if (empty($attributes['name'])) {
            throw new \InvalidArgumentException('Lead name is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Lead tenant_slug is required.');
        }

        return Lead::create([
            'name'        => $attributes['name'],
            'tenant_slug' => $attributes['tenant_slug'],
            'email'       => $attributes['email']   ?? null,
            'phone'       => $attributes['phone']   ?? null,
            'company'     => $attributes['company'] ?? null,
            'source'      => $attributes['source']  ?? null,
            'status'      => $attributes['status']  ?? 'new',
            'notes'       => $attributes['notes']   ?? null,
        ]);
    }

    /**
     * Create a new Opportunity.
     *
     * @param array{
     *     title:                string,
     *     tenant_slug:          string,
     *     client_id?:           string|null,
     *     description?:         string|null,
     *     estimated_value?:     float|null,
     *     currency?:            string,
     *     expected_close_date?: string|null,
     *     notes?:               string|null,
     * } $attributes
     *
     * @throws \InvalidArgumentException If required fields are missing.
     */
    public function createOpportunity(array $attributes): Opportunity
    {
        if (empty($attributes['title'])) {
            throw new \InvalidArgumentException('Opportunity title is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Opportunity tenant_slug is required.');
        }

        return Opportunity::create([
            'title'               => $attributes['title'],
            'tenant_slug'         => $attributes['tenant_slug'],
            'client_id'           => $attributes['client_id']           ?? null,
            'description'         => $attributes['description']         ?? null,
            'estimated_value'     => $attributes['estimated_value']     ?? null,
            'currency'            => $attributes['currency']            ?? 'QAR',
            'status'              => 'open',
            'expected_close_date' => $attributes['expected_close_date'] ?? null,
            'notes'               => $attributes['notes']               ?? null,
        ]);
    }

    /**
     * Mark an Opportunity as Won.
     *
     * Dispatches: OpportunityWon event (A1 MOD-04).
     * Audit: crm.opportunity.won (NEW-003).
     *
     * @throws \InvalidArgumentException If the opportunity is not found or already terminal.
     */
    public function markOpportunityWon(string $opportunityId, string|null $actorId = null): Opportunity
    {
        if (empty($opportunityId)) {
            throw new \InvalidArgumentException('opportunityId must not be empty.');
        }

        $opportunity = Opportunity::find($opportunityId);

        if ($opportunity === null) {
            throw new \InvalidArgumentException("Opportunity [{$opportunityId}] not found.");
        }

        if ($opportunity->isTerminal()) {
            throw new \InvalidArgumentException(
                "Opportunity [{$opportunityId}] is already in terminal state [{$opportunity->status}]."
            );
        }

        $wonAt = now();

        $opportunity->update([
            'status' => 'won',
            'won_at' => $wonAt,
        ]);

        $opportunity->refresh();

        // Audit: crm.opportunity.won (NEW-003 / TASK-001C)
        $this->auditService->logEvent(
            'crm.opportunity.won',
            $actorId,
            [
                'tenant_slug'  => $opportunity->tenant_slug,
                'subject_type' => Opportunity::class,
                'subject_id'   => $opportunity->id,
                'after'        => ['status' => 'won', 'won_at' => $wonAt->toIso8601String()],
            ]
        );

        // Domain event: OpportunityWon (A1 MOD-04)
        Event::dispatch(new OpportunityWon(
            opportunityId: $opportunity->id,
            clientId:      $opportunity->client_id,
            tenantSlug:    $opportunity->tenant_slug,
            wonAt:         new \DateTimeImmutable($wonAt->toIso8601String()),
        ));

        return $opportunity;
    }

    /**
     * Mark an Opportunity as Lost.
     *
     * Dispatches: OpportunityLost event (A1 MOD-04).
     * Audit: crm.opportunity.lost (NEW-003).
     *
     * @throws \InvalidArgumentException If the opportunity is not found or already terminal.
     */
    public function markOpportunityLost(
        string $opportunityId,
        string|null $lostReason = null,
        string|null $actorId = null,
    ): Opportunity {
        if (empty($opportunityId)) {
            throw new \InvalidArgumentException('opportunityId must not be empty.');
        }

        $opportunity = Opportunity::find($opportunityId);

        if ($opportunity === null) {
            throw new \InvalidArgumentException("Opportunity [{$opportunityId}] not found.");
        }

        if ($opportunity->isTerminal()) {
            throw new \InvalidArgumentException(
                "Opportunity [{$opportunityId}] is already in terminal state [{$opportunity->status}]."
            );
        }

        $lostAt = now();

        $opportunity->update([
            'status'      => 'lost',
            'lost_at'     => $lostAt,
            'lost_reason' => $lostReason,
        ]);

        $opportunity->refresh();

        // Audit: crm.opportunity.lost (NEW-003 / TASK-001C)
        $this->auditService->logEvent(
            'crm.opportunity.lost',
            $actorId,
            [
                'tenant_slug'  => $opportunity->tenant_slug,
                'subject_type' => Opportunity::class,
                'subject_id'   => $opportunity->id,
                'after'        => [
                    'status'      => 'lost',
                    'lost_at'     => $lostAt->toIso8601String(),
                    'lost_reason' => $lostReason,
                ],
            ]
        );

        // Domain event: OpportunityLost (A1 MOD-04)
        Event::dispatch(new OpportunityLost(
            opportunityId: $opportunity->id,
            clientId:      $opportunity->client_id,
            lostReason:    $lostReason,
            tenantSlug:    $opportunity->tenant_slug,
            lostAt:        new \DateTimeImmutable($lostAt->toIso8601String()),
        ));

        return $opportunity;
    }

    /**
     * Record a CRM Activity (interaction history).
     *
     * @param array{
     *     subject_type: string,
     *     subject_id:   string,
     *     type:         string,
     *     summary:      string,
     *     tenant_slug:  string,
     *     occurred_at?: string|null,
     *     actor_id?:    string|null,
     *     notes?:       string|null,
     * } $attributes
     *
     * @throws \InvalidArgumentException If required fields are missing.
     */
    public function recordActivity(array $attributes): CRMActivity
    {
        foreach (['subject_type', 'subject_id', 'type', 'summary', 'tenant_slug'] as $field) {
            if (empty($attributes[$field])) {
                throw new \InvalidArgumentException("CRMActivity {$field} is required.");
            }
        }

        return CRMActivity::create([
            'subject_type' => $attributes['subject_type'],
            'subject_id'   => $attributes['subject_id'],
            'type'         => $attributes['type'],
            'summary'      => $attributes['summary'],
            'tenant_slug'  => $attributes['tenant_slug'],
            'occurred_at'  => $attributes['occurred_at'] ?? now(),
            'actor_id'     => $attributes['actor_id']    ?? null,
            'notes'        => $attributes['notes']       ?? null,
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================
    // PIPELINE — CAP-020
    // =========================================================================

    /**
     * Lead pipeline stages (CAP-020 V1 Core).
     */
    public const LEAD_STAGES = ['new', 'contacted', 'qualified', 'converted', 'lost'];

    /**
     * Opportunity pipeline stages (CAP-020 V1 Core).
     */
    public const OPPORTUNITY_STAGES = ['open', 'won', 'lost', 'cancelled'];

    /**
     * listLeads — list all Leads for the active tenant, optionally filtered by stage.
     *
     * CAP-020 — Pipeline List View (V1 Core).
     *
     * @param  string|null  $stage  Optional pipeline stage filter.
     * @return \Illuminate\Database\Eloquent\Collection<int, Lead>
     * @throws \InvalidArgumentException If the stage value is invalid.
     */
    public function listLeads(?string $stage = null): \Illuminate\Database\Eloquent\Collection
    {
        if ($stage !== null && ! in_array($stage, self::LEAD_STAGES, true)) {
            throw new \InvalidArgumentException(
                "Invalid lead stage [{$stage}]. Allowed: " . implode(', ', self::LEAD_STAGES) . '.'
            );
        }

        $query = Lead::query();

        if ($stage !== null) {
            $query->where('status', $stage);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * listOpportunities — list all Opportunities for the active tenant, optionally filtered by stage.
     *
     * CAP-020 — Pipeline List View (V1 Core).
     *
     * @param  string|null  $stage  Optional pipeline stage filter.
     * @return \Illuminate\Database\Eloquent\Collection<int, Opportunity>
     * @throws \InvalidArgumentException If the stage value is invalid.
     */
    public function listOpportunities(?string $stage = null): \Illuminate\Database\Eloquent\Collection
    {
        if ($stage !== null && ! in_array($stage, self::OPPORTUNITY_STAGES, true)) {
            throw new \InvalidArgumentException(
                "Invalid opportunity stage [{$stage}]. Allowed: " . implode(', ', self::OPPORTUNITY_STAGES) . '.'
            );
        }

        $query = Opportunity::query();

        if ($stage !== null) {
            $query->where('status', $stage);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * updateLeadStage — update the pipeline stage of a Lead.
     *
     * CAP-020 — Stage Update (V1 Core).
     *
     * @param  string  $leadId  UUID of the Lead.
     * @param  string  $stage   New pipeline stage.
     * @param  string|null  $actorId  Actor performing the update (for audit).
     * @return Lead
     * @throws \InvalidArgumentException If the lead is not found or stage is invalid.
     */
    public function updateLeadStage(string $leadId, string $stage, ?string $actorId = null): Lead
    {
        if (empty($leadId)) {
            throw new \InvalidArgumentException('leadId must not be empty.');
        }

        if (! in_array($stage, self::LEAD_STAGES, true)) {
            throw new \InvalidArgumentException(
                "Invalid lead stage [{$stage}]. Allowed: " . implode(', ', self::LEAD_STAGES) . '.'
            );
        }

        $lead = Lead::find($leadId);

        if ($lead === null) {
            throw new \InvalidArgumentException("Lead [{$leadId}] not found.");
        }

        $previousStage = $lead->status;
        $lead->status = $stage;
        $lead->save();

        $this->auditService->logEvent(
            'crm.lead.stage_updated',
            $actorId,
            [
                'lead_id'        => $leadId,
                'previous_stage' => $previousStage,
                'new_stage'      => $stage,
            ]
        );

        return $lead;
    }

    /**
     * updateOpportunityStage — update the pipeline stage of an Opportunity.
     *
     * CAP-020 — Stage Update (V1 Core).
     *
     * Note: For 'won' and 'lost' transitions, prefer markOpportunityWon() /
     * markOpportunityLost() which dispatch domain events. This method is for
     * direct stage updates (e.g. 'open' → 'cancelled').
     *
     * @param  string  $opportunityId  UUID of the Opportunity.
     * @param  string  $stage          New pipeline stage.
     * @param  string|null  $actorId   Actor performing the update (for audit).
     * @return Opportunity
     * @throws \InvalidArgumentException If the opportunity is not found or stage is invalid.
     */
    public function updateOpportunityStage(string $opportunityId, string $stage, ?string $actorId = null): Opportunity
    {
        if (empty($opportunityId)) {
            throw new \InvalidArgumentException('opportunityId must not be empty.');
        }

        if (! in_array($stage, self::OPPORTUNITY_STAGES, true)) {
            throw new \InvalidArgumentException(
                "Invalid opportunity stage [{$stage}]. Allowed: " . implode(', ', self::OPPORTUNITY_STAGES) . '.'
            );
        }

        $opportunity = Opportunity::find($opportunityId);

        if ($opportunity === null) {
            throw new \InvalidArgumentException("Opportunity [{$opportunityId}] not found.");
        }

        $previousStage = $opportunity->status;
        $opportunity->status = $stage;
        $opportunity->save();

        $this->auditService->logEvent(
            'crm.opportunity.stage_updated',
            $actorId,
            [
                'opportunity_id' => $opportunityId,
                'previous_stage' => $previousStage,
                'new_stage'      => $stage,
            ]
        );

        return $opportunity;
    }

    // =========================================================================

    /**
     * Validate required Client attributes.
     *
     * @param array<string,mixed> $attributes
     * @throws \InvalidArgumentException
     */
    private function validateClientAttributes(array $attributes): void
    {
        if (empty($attributes['type'])) {
            throw new \InvalidArgumentException('Client type is required.');
        }
        if (!in_array($attributes['type'], ['company', 'individual'], true)) {
            throw new \InvalidArgumentException(
                "Client type must be 'company' or 'individual'. Got: [{$attributes['type']}]."
            );
        }
        if (empty($attributes['name'])) {
            throw new \InvalidArgumentException('Client name is required.');
        }
        if (empty($attributes['tenant_slug'])) {
            throw new \InvalidArgumentException('Client tenant_slug is required.');
        }
    }
}
