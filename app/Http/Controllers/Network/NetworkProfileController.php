<?php

declare(strict_types=1);

namespace App\Http\Controllers\Network;

use App\Foundation\TenantContext;
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
 *  - Tenant context resolved from TenantContext (SPA PATH C / PAT PATH B).
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
                'profile_type'    => 'required|in:owner,consultant,engineer,contractor,main_contractor,subcontractor,supplier',
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
        $tenantSlug = app(TenantContext::class)->slug;

        $profile = $this->service->createProfile($validated, $actorId, $tenantSlug);

        return response()->json(['data' => $profile->toArray()], 201);
    }

    // -------------------------------------------------------------------------
    // GET /v1/network/profiles
    // -------------------------------------------------------------------------

    public function list(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'status', 'region', 'scope']);
        $tenantSlug = app(TenantContext::class)->slug;
        $paginated = $this->service->listProfiles($filters, $tenantSlug);

        $scope = ($filters['scope'] ?? 'directory') === 'mine' ? 'mine' : 'directory';
        $items = collect($paginated->items())->map(function ($profile) use ($scope) {
            // Directory never returns private contact/tenant fields — even for own rows.
            if ($scope === 'mine') {
                return $this->service->toOwnerArray($profile);
            }

            return $this->service->toPublicCard($profile);
        })->values()->all();

        return response()->json([
            'data' => $items,
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'scope' => $scope === 'mine' ? 'mine' : 'directory',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/network/profiles/{id}
    // -------------------------------------------------------------------------

    public function show(string $id): JsonResponse
    {
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $resolved = $this->service->resolveProfileForViewer($id, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        }

        return response()->json(['data' => $resolved['data'], 'visibility' => $resolved['mode']]);
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
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $profile = $this->service->updateProfile($id, $validated, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $profile->toArray()]);
    }

    // -------------------------------------------------------------------------
    // PATCH /v1/network/profiles/{id}/activate
    // -------------------------------------------------------------------------

    public function activate(Request $request, string $id): JsonResponse
    {
        $actorId    = (string) auth()->id();
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $profile = $this->service->activateProfile($id, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Network profile not found.'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
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
        $tenantSlug = app(TenantContext::class)->slug;

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
        $tenantSlug = app(TenantContext::class)->slug;

        try {
            $this->service->unlinkFromTenantSupplier($supplierId, $actorId, $tenantSlug);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Supplier not found.'], 404);
        }

        return response()->json(['message' => 'Network profile link removed from supplier.']);
    }
}
