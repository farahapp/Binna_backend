<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Network\TenderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * TenderController — HTTP API for Network Tender Foundation management.
 *
 * NETWORK-002 — Network Tender Foundation
 *
 * Architectural constraints:
 *  - Backend authority: all validation and authorization enforced here.
 *  - Tenant context resolved from X-Tenant header (existing middleware).
 *  - Actor identity from authenticated Sanctum user.
 *  - Visibility access control enforced via TenderService.
 *  - No Bid, Evaluation, Award endpoints.
 *  - No frontend logic.
 */
class TenderController extends Controller
{
    public function __construct(
        private readonly TenderService $service,
    ) {}

    // -------------------------------------------------------------------------
    // POST /v1/network/tenders
    // -------------------------------------------------------------------------

    public function create(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'publisher_profile_id' => 'required|uuid',
                'title'                => 'required|string|max:255',
                'category'             => 'required|string|max:100',
                'description'          => 'nullable|string',
                'region'               => 'nullable|string|max:100',
                'deadline'             => 'nullable|date',
                'budget_min'           => 'nullable|numeric|min:0',
                'budget_max'           => 'nullable|numeric|min:0|gte:budget_min',
                'specializations'      => 'nullable|array',
                'requirements'         => 'nullable|array',
                'participant_types'    => 'nullable|array',
                'participant_types.*'  => 'string|in:owner,engineer,contractor,main_contractor,subcontractor,supplier',
                'visibility'           => 'nullable|in:public,private,invited,matched',
                'match_criteria'       => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $tender = $this->service->createTender($validated, $actorId, $tenantSlug);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $tender->toArray()], 201);
    }

    // -------------------------------------------------------------------------
    // GET /v1/network/tenders
    // -------------------------------------------------------------------------

    public function list(Request $request): JsonResponse
    {
        $filters    = $request->only(['status', 'visibility', 'category', 'region', 'publisher_profile_id']);
        $tenantSlug = (string) $request->header('X-Tenant', '');

        $paginated = $this->service->listTenders($filters, $tenantSlug);

        return response()->json($paginated);
    }

    // -------------------------------------------------------------------------
    // GET /v1/network/tenders/{id}
    // -------------------------------------------------------------------------

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $tender = $this->service->getTender($id, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $tender->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/tenders/{id}
    // -------------------------------------------------------------------------

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title'             => 'sometimes|string|max:255',
                'description'       => 'nullable|string',
                'category'          => 'sometimes|string|max:100',
                'region'            => 'nullable|string|max:100',
                'deadline'          => 'nullable|date',
                'budget_min'        => 'nullable|numeric|min:0',
                'budget_max'        => 'nullable|numeric|min:0',
                'specializations'   => 'nullable|array',
                'requirements'      => 'nullable|array',
                'participant_types' => 'nullable|array',
                'participant_types.*' => 'string|in:owner,engineer,contractor,main_contractor,subcontractor,supplier',
                'visibility'        => 'nullable|in:public,private,invited,matched',
                'match_criteria'    => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $tender = $this->service->updateTender($id, $validated, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $tender->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/tenders/{id}/publish
    // -------------------------------------------------------------------------

    public function publish(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $tender = $this->service->publishTender($id, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $tender->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/tenders/{id}/close
    // -------------------------------------------------------------------------

    public function close(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $tender = $this->service->closeTender($id, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $tender->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/tenders/{id}/award
    // -------------------------------------------------------------------------

    /**
     * Mark tender as awarded (lifecycle foundation only — no award workflow).
     */
    public function award(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $tender = $this->service->markAwarded($id, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network tender not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $tender->toArray()]);
    }
}
