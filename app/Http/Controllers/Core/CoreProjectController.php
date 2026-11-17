<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Core\CoreProjectService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CoreProjectController extends Controller
{
    public function __construct(
        private readonly CoreProjectService $service,
    ) {}

    public function createProperty(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'property_type' => 'nullable|string|max:50',
                'address_line' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'region' => 'nullable|string|max:100',
                'country' => 'nullable|string|size:2',
                'plot_number' => 'nullable|string|max:100',
                'title_deed_ref' => 'nullable|string|max:100',
                'owner_network_profile_id' => 'nullable|uuid',
                'metadata' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $property = $this->service->createProperty(
            $validated,
            (string) auth()->id(),
            app(TenantContext::class)->slug,
        );

        return response()->json(['data' => $property->toArray()], 201);
    }

    public function listProperties(): JsonResponse
    {
        $page = $this->service->listProperties(app(TenantContext::class)->slug);

        return response()->json($page);
    }

    public function showProperty(string $id): JsonResponse
    {
        try {
            $property = $this->service->getProperty($id, app(TenantContext::class)->slug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Property not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $property->toArray()]);
    }

    public function createIdentity(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'org_type' => 'required|in:owner,contractor,consultant',
                'property_id' => 'nullable|uuid',
                'description' => 'nullable|string',
                'region' => 'nullable|string|max:100',
                'canonical_code' => 'nullable|string|max:64',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $identity = $this->service->createProjectIdentity(
                $validated,
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $identity->toArray()], 201);
    }

    public function listIdentities(): JsonResponse
    {
        return response()->json($this->service->listProjectIdentities(app(TenantContext::class)->slug));
    }

    public function showIdentity(string $id): JsonResponse
    {
        try {
            $identity = $this->service->getProjectIdentity($id, app(TenantContext::class)->slug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Project identity not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $identity->toArray()]);
    }

    public function inviteParticipant(Request $request, string $identityId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'participant_tenant_slug' => 'required|string|max:100',
                'organization_type' => 'required|in:owner,contractor,consultant',
                'project_role' => 'required|string|max:50',
                'participant_network_profile_id' => 'nullable|uuid',
                'access_scope' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $participation = $this->service->inviteParticipant(
                $identityId,
                $validated,
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Project identity not found.'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not authorized') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $participation->toArray()], 201);
    }

    public function respondParticipation(Request $request, string $participationId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'decision' => 'required|in:accept,decline',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $participation = $this->service->respondToParticipationInvitation(
                $participationId,
                $validated['decision'],
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Participation not found.'], 404);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not authorized') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $participation->toArray()]);
    }

    public function listLinkRequests(Request $request): JsonResponse
    {
        $filters = $request->only(['direction', 'status', 'per_page']);
        $result = $this->service->listLinkRequests(
            app(TenantContext::class)->slug,
            $filters,
        );

        return response()->json($result);
    }

    public function createLinkRequest(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'source_project_identity_id' => 'nullable|uuid',
                'target_project_identity_id' => 'nullable|uuid',
                'property_id' => 'nullable|uuid',
                'requester_org_type' => 'required|in:owner,contractor,consultant',
                'match_type' => 'nullable|in:exact,candidate,manual',
                'evidence' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $link = $this->service->createLinkRequest(
                $validated,
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $link->toArray()], 201);
    }

    public function decideLinkRequest(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'decision' => 'required|in:approve,reject',
                'reason' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $link = $this->service->decideLinkRequest(
                $id,
                $validated['decision'],
                (string) auth()->id(),
                app(TenantContext::class)->slug,
                $validated['reason'] ?? null,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Link request not found.'], 404);
        } catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not authorized') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['data' => $link->toArray()]);
    }

    public function listEngagements(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'per_page']);
        $result = $this->service->listEngagements(
            app(TenantContext::class)->slug,
            $filters,
        );

        return response()->json($result);
    }

    public function showEngagement(string $id): JsonResponse
    {
        try {
            $engagement = $this->service->getEngagement(
                $id,
                app(TenantContext::class)->slug,
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Engagement not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $engagement->toArray()]);
    }

    public function createEngagement(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'engagement_type' => 'required|in:consultancy,construction,other',
                'provider_tenant_slug' => 'required|string|max:100',
                'project_identity_id' => 'nullable|uuid',
                'network_award_id' => 'nullable|uuid',
                'network_tender_id' => 'nullable|uuid',
                'provider_network_profile_id' => 'nullable|uuid',
                'client_network_profile_id' => 'nullable|uuid',
                'provider_role' => 'nullable|string|max:50',
                'provider_org_type' => 'nullable|in:owner,contractor,consultant',
                'contract_value' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|size:3',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'terms' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            $engagement = $this->service->createEngagement(
                $validated,
                (string) auth()->id(),
                app(TenantContext::class)->slug,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $engagement->toArray()], 201);
    }
}
