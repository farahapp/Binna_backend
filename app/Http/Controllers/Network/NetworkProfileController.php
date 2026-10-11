<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Network\NetworkProfileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * NetworkProfileController — HTTP API for Network Profile management.
 *
 * NETWORK-001 — Network Foundation & Profile
 *
 * Architectural constraints:
 *  - Backend authority: all validation and authorization enforced here.
 *  - Tenant context resolved from X-Tenant header (existing middleware).
 *  - Actor identity from authenticated Sanctum user.
 *  - No frontend logic.
 */
class NetworkProfileController extends Controller
{
    public function __construct(
        private readonly NetworkProfileService $service,
    ) {}

    // -------------------------------------------------------------------------
    // POST /v1/network/profiles
    // -------------------------------------------------------------------------

    public function create(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_name'    => 'required|string|max:255',
                'profile_type'    => 'required|in:owner,engineer,contractor,main_contractor,subcontractor,supplier',
                'region'          => 'nullable|string|max:100',
                'specializations' => 'nullable|array',
                'contact_email'   => 'nullable|email|max:255',
                'contact_phone'   => 'nullable|string|max:50',
                'website'         => 'nullable|url|max:255',
                'description'     => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        $profile = $this->service->createProfile($validated, $actorId, $tenantSlug);

        return response()->json(['data' => $profile->toArray()], 201);
    }

    // -------------------------------------------------------------------------
    // GET /v1/network/profiles
    // -------------------------------------------------------------------------

    public function list(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'status', 'region']);
        $paginated = $this->service->listProfiles($filters);

        return response()->json($paginated);
    }

    // -------------------------------------------------------------------------
    // GET /v1/network/profiles/{id}
    // -------------------------------------------------------------------------

    public function show(string $id): JsonResponse
    {
        try {
            $profile = $this->service->getProfile($id);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        }

        return response()->json(['data' => $profile->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/profiles/{id}
    // -------------------------------------------------------------------------

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'company_name'    => 'sometimes|string|max:255',
                'region'          => 'nullable|string|max:100',
                'specializations' => 'nullable|array',
                'contact_email'   => 'nullable|email|max:255',
                'contact_phone'   => 'nullable|string|max:50',
                'website'         => 'nullable|url|max:255',
                'description'     => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $profile = $this->service->updateProfile($id, $validated, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        }

        return response()->json(['data' => $profile->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/profiles/{id}/activate
    // -------------------------------------------------------------------------

    public function activate(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $profile = $this->service->activateProfile($id, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $profile->toArray()]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/network/profiles/{id}/link-supplier
    // -------------------------------------------------------------------------

    public function linkSupplier(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'tenant_supplier_id' => 'required|uuid',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $this->service->linkToTenantSupplier(
                $id,
                $validated['tenant_supplier_id'],
                $actorId,
                $tenantSlug
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile or supplier not found.'], 404);
        }

        return response()->json(['message' => 'Supplier linked to network profile successfully.']);
    }

    // -------------------------------------------------------------------------
    // DELETE /v1/suppliers/{supplierId}/network-link
    // -------------------------------------------------------------------------

    public function unlinkSupplier(Request $request, string $supplierId): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = (string) $request->header('X-Tenant', '');

        try {
            $this->service->unlinkFromTenantSupplier($supplierId, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Supplier not found.'], 404);
        }

        return response()->json(['message' => 'Network profile link removed from supplier.']);
    }
}
