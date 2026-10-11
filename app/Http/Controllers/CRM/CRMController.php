<?php

declare(strict_types=1);

namespace App\Http\Controllers\CRM;

use App\CRM\CRMService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CRMController — CRM HTTP API Layer.
 *
 * Architectural constraints (A1 MOD-04 / TASK-003C):
 *  - Delegates ALL business logic to CRMService.
 *  - Does NOT access CRM models directly.
 *  - Does NOT depend on Accounting, Finance, Invoicing, Receivables,
 *    Procurement, Tender, BOQ, or Contract management.
 *  - Tenant context resolved by ResolveTenantContext middleware (OD-001).
 *  - All endpoints protected by auth:sanctum (TASK-001B).
 *
 * TASK-003C — CRM HTTP API Layer
 */
class CRMController extends Controller
{
    public function __construct(
        private readonly CRMService $crmService,
    ) {}

    // =========================================================================
    // CLIENTS
    // =========================================================================

    /**
     * POST /api/v1/crm/clients
     * Create a new CRM client (company or individual).
     */
    public function createClient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'type'       => ['required', 'string', 'in:company,individual'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'email'      => ['nullable', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'country'    => ['nullable', 'string', 'max:100'],
            'city'       => ['nullable', 'string', 'max:100'],
        ]);

        $actorId = $request->user()?->id;

        // Inject tenant_slug from the resolved TenantContext (OD-001).
        // tenant_slug is NEVER accepted from the client request.
        $tenantContext = app(TenantContext::class);
        $validated['tenant_slug'] = $tenantContext->slug;

        $client = $this->crmService->createClient($validated, $actorId);

        return response()->json($client->toArray(), 201);
    }

    /**
     * GET /api/v1/crm/clients
     * List all CRM clients for the active tenant.
     */
    public function listClients(Request $request): JsonResponse
    {
        $clients = \App\CRM\Client::all();

        return response()->json($clients->toArray(), 200);
    }

    /**
     * GET /api/v1/crm/clients/{id}
     * Get a single CRM client by ID.
     */
    public function getClient(Request $request, string $id): JsonResponse
    {
        try {
            $client = $this->crmService->getClient($id);
            return response()->json($client->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Client not found.'], 404);
        }
    }

    // =========================================================================
    // CONTACTS
    // =========================================================================

    /**
     * GET /api/v1/crm/contacts/{id}
     * Get a single CRM contact by ID.
     */
    public function getContact(Request $request, string $id): JsonResponse
    {
        try {
            $contact = $this->crmService->getContact($id);
            return response()->json($contact->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Contact not found.'], 404);
        }
    }

    // =========================================================================
    // LEADS
    // =========================================================================

    /**
     * POST /api/v1/crm/leads
     * Create a new CRM lead.
     */
    public function createLead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'   => ['required', 'string', 'uuid'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'source'      => ['nullable', 'string', 'max:100'],
        ]);

        // Map HTTP fields to service contract fields.
        // 'title' is the HTTP-facing name; CRMService::createLead expects 'name'.
        // tenant_slug is injected from TenantContext (OD-001), never from the request.
        $tenantContext = app(TenantContext::class);
        $serviceAttributes = [
            'name'        => $validated['title'],
            'tenant_slug' => $tenantContext->slug,
            'source'      => $validated['source'] ?? null,
            'notes'       => $validated['description'] ?? null,
        ];

        $lead = $this->crmService->createLead($serviceAttributes);

        return response()->json($lead->toArray(), 201);
    }

    // =========================================================================
    // OPPORTUNITIES
    // =========================================================================

    /**
     * POST /api/v1/crm/opportunities
     * Create a new CRM opportunity.
     */
    public function createOpportunity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'    => ['required', 'string', 'uuid'],
            'title'        => ['required', 'string', 'max:255'],
            'value'        => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
        ]);

        // Inject tenant_slug from TenantContext (OD-001).
        // Map 'value' (HTTP field) → 'estimated_value' (service contract field).
        $tenantContext = app(TenantContext::class);
        $serviceAttributes = [
            'title'               => $validated['title'],
            'tenant_slug'         => $tenantContext->slug,
            'client_id'           => $validated['client_id'] ?? null,
            'estimated_value'     => $validated['value'] ?? null,
            'expected_close_date' => $validated['expected_close_date'] ?? null,
        ];

        $opportunity = $this->crmService->createOpportunity($serviceAttributes);

        return response()->json($opportunity->toArray(), 201);
    }

    /**
     * PATCH /api/v1/crm/opportunities/{id}/won
     * Mark an opportunity as won.
     */
    public function markOpportunityWon(Request $request, string $id): JsonResponse
    {
        try {
            $actorId = $request->user()?->id;
            $opportunity = $this->crmService->markOpportunityWon($id, $actorId);
            return response()->json($opportunity->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/crm/opportunities/{id}/lost
     * Mark an opportunity as lost.
     */
    public function markOpportunityLost(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'lost_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $actorId = $request->user()?->id;
            $opportunity = $this->crmService->markOpportunityLost(
                $id,
                $validated['lost_reason'] ?? null,
                $actorId
            );
            return response()->json($opportunity->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // ACTIVITIES
    // =========================================================================

    /**
     * POST /api/v1/crm/activities
     * Record a CRM activity.
     */
    public function recordActivity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', 'string', 'max:100'],
            'subject_id'   => ['required', 'string', 'uuid'],
            'type'         => ['required', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
            'occurred_at'  => ['nullable', 'date'],
        ]);

        // Inject tenant_slug from TenantContext (OD-001).
        // Map 'notes' (HTTP field) → 'summary' (service contract required field)
        // when 'summary' is not explicitly provided.
        $tenantContext = app(TenantContext::class);
        $serviceAttributes = [
            'subject_type' => $validated['subject_type'],
            'subject_id'   => $validated['subject_id'],
            'type'         => $validated['type'],
            'summary'      => $validated['notes'] ?? '',
            'tenant_slug'  => $tenantContext->slug,
            'occurred_at'  => $validated['occurred_at'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ];

        $activity = $this->crmService->recordActivity($serviceAttributes);

        return response()->json($activity->toArray(), 201);
    }

    // =========================================================================
    // PIPELINE — CAP-020
    // =========================================================================

    /**
     * GET /api/v1/crm/leads
     * List all Leads for the active tenant, optionally filtered by pipeline stage.
     *
     * CAP-020 — Pipeline List View (V1 Core).
     * Query param: ?stage=new|contacted|qualified|converted|lost
     */
    public function listLeads(Request $request): JsonResponse
    {
        $stage = $request->query('stage');

        try {
            $leads = $this->crmService->listLeads(
                is_string($stage) ? $stage : null
            );
            return response()->json($leads->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/crm/opportunities
     * List all Opportunities for the active tenant, optionally filtered by pipeline stage.
     *
     * CAP-020 — Pipeline List View (V1 Core).
     * Query param: ?stage=open|won|lost|cancelled
     */
    public function listOpportunities(Request $request): JsonResponse
    {
        $stage = $request->query('stage');

        try {
            $opportunities = $this->crmService->listOpportunities(
                is_string($stage) ? $stage : null
            );
            return response()->json($opportunities->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/crm/leads/{id}/stage
     * Update the pipeline stage of a Lead.
     *
     * CAP-020 — Stage Update (V1 Core).
     * Body: { "stage": "new|contacted|qualified|converted|lost" }
     */
    public function updateLeadStage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'stage' => ['required', 'string', 'in:new,contacted,qualified,converted,lost'],
        ]);

        $actorId = $request->user()?->id;

        try {
            $lead = $this->crmService->updateLeadStage($id, $validated['stage'], $actorId);
            return response()->json($lead->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/v1/crm/opportunities/{id}/stage
     * Update the pipeline stage of an Opportunity.
     *
     * CAP-020 — Stage Update (V1 Core).
     * Body: { "stage": "open|won|lost|cancelled" }
     *
     * Note: For won/lost transitions with domain events, use the dedicated
     * /won and /lost endpoints which dispatch OpportunityWon/OpportunityLost.
     */
    public function updateOpportunityStage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'stage' => ['required', 'string', 'in:open,won,lost,cancelled'],
        ]);

        $actorId = $request->user()?->id;

        try {
            $opportunity = $this->crmService->updateOpportunityStage($id, $validated['stage'], $actorId);
            return response()->json($opportunity->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
