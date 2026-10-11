<?php

declare(strict_types=1);

namespace App\Http\Controllers\Equipment;

use App\Equipment\EquipmentService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EquipmentController — Thin HTTP layer for Equipment/Asset Management.
 *
 * Architecture: validate → get actorId + tenantSlug → call EquipmentService → return JSON
 * Business logic stays in EquipmentService ONLY (TASK-011C / CAP-100 V1.5).
 * No Equipment Rental (OD-012 IMPL-DEFERRABLE — V2/Future).
 */
class EquipmentController extends Controller
{
    public function __construct(
        private readonly EquipmentService $equipmentService,
    ) {}

    // -------------------------------------------------------------------------
    // Asset Management
    // -------------------------------------------------------------------------

    /** POST /api/v1/equipment/assets — Permission: equipment.register */
    public function registerAsset(Request $request): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.register')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'asset_code'        => ['required', 'string', 'max:50'],
            'name'              => ['required', 'string', 'max:200'],
            'description'       => ['sometimes', 'nullable', 'string'],
            'category'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'asset_type'        => ['sometimes', 'string', 'in:owned,leased'],
            'acquisition_date'  => ['sometimes', 'nullable', 'date'],
            'acquisition_cost'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'          => ['sometimes', 'string', 'size:3'],
            'depreciation_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'notes'             => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $asset = $this->equipmentService->registerAsset(
                tenantSlug:       $tenantSlug,
                assetCode:        $validated['asset_code'],
                name:             $validated['name'],
                description:      $validated['description'] ?? null,
                category:         $validated['category'] ?? null,
                assetType:        $validated['asset_type'] ?? 'owned',
                acquisitionDate:  $validated['acquisition_date'] ?? null,
                acquisitionCost:  isset($validated['acquisition_cost']) ? (float)$validated['acquisition_cost'] : null,
                currency:         $validated['currency'] ?? 'QAR',
                depreciationRate: isset($validated['depreciation_rate']) ? (float)$validated['depreciation_rate'] : null,
                notes:            $validated['notes'] ?? null,
                actorId:          $actorId,
            );
            return response()->json($asset->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** GET /api/v1/equipment/assets/{id} */
    public function getAsset(Request $request, string $id): JsonResponse
    {
        try {
            $asset = $this->equipmentService->getAsset($id);
            return response()->json($asset->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'Not found')) {
                return response()->json(['message' => $e->getMessage()], 404);
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** GET /api/v1/equipment/assets */
    public function listAssets(Request $request): JsonResponse
    {
        $tenantSlug = app(TenantContext::class)->slug;
        $filters    = $request->only(['status', 'category', 'project_id']);
        $assets     = $this->equipmentService->listAssets($tenantSlug, $filters);
        return response()->json($assets->toArray(), 200);
    }

    /** POST /api/v1/equipment/assets/{id}/allocate — Permission: equipment.allocate */
    public function allocateToProject(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.allocate')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
            'notes'      => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $allocation = $this->equipmentService->allocateToProject(
                tenantSlug: $tenantSlug,
                assetId:    $id,
                projectId:  $validated['project_id'],
                notes:      $validated['notes'] ?? null,
                actorId:    $actorId,
            );
            return response()->json($allocation->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/v1/equipment/assets/{id}/release — Permission: equipment.allocate */
    public function releaseFromProject(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.allocate')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated  = $request->validate(['notes' => ['sometimes', 'nullable', 'string']]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $asset = $this->equipmentService->releaseFromProject(
                tenantSlug: $tenantSlug,
                assetId:    $id,
                notes:      $validated['notes'] ?? null,
                actorId:    $actorId,
            );
            return response()->json($asset->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/v1/equipment/assets/{id}/maintenance — Permission: equipment.maintenance */
    public function sendToMaintenance(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.maintenance')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'maintenance_type' => ['sometimes', 'string', 'in:scheduled,corrective,preventive'],
            'scheduled_date'   => ['sometimes', 'nullable', 'date'],
            'description'      => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $record = $this->equipmentService->sendToMaintenance(
                tenantSlug:      $tenantSlug,
                assetId:         $id,
                maintenanceType: $validated['maintenance_type'] ?? 'scheduled',
                scheduledDate:   $validated['scheduled_date'] ?? null,
                description:     $validated['description'] ?? null,
                actorId:         $actorId,
            );
            return response()->json($record->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/v1/equipment/maintenance/{id}/complete — Permission: equipment.maintenance */
    public function completeMaintenance(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.maintenance')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'cost'         => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'performed_by' => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes'        => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $record = $this->equipmentService->completeMaintenance(
                tenantSlug:          $tenantSlug,
                maintenanceRecordId: $id,
                cost:                isset($validated['cost']) ? (float)$validated['cost'] : null,
                performedBy:         $validated['performed_by'] ?? null,
                notes:               $validated['notes'] ?? null,
                actorId:             $actorId,
            );
            return response()->json($record->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/v1/equipment/assets/{id}/decommission — Permission: equipment.decommission */
    public function decommissionAsset(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.decommission')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated  = $request->validate(['notes' => ['sometimes', 'nullable', 'string']]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $asset = $this->equipmentService->decommissionAsset(
                tenantSlug: $tenantSlug,
                assetId:    $id,
                notes:      $validated['notes'] ?? null,
                actorId:    $actorId,
            );
            return response()->json($asset->toArray(), 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/v1/equipment/assets/{id}/cost-allocation — Permission: equipment.cost */
    public function recordCostAllocation(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->hasPermission('equipment.cost')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'project_id'     => ['required', 'string'],
            'period_start'   => ['required', 'date'],
            'period_end'     => ['required', 'date', 'after_or_equal:period_start'],
            'allocated_cost' => ['required', 'numeric', 'min:0'],
            'currency'       => ['sometimes', 'string', 'size:3'],
            'cost_code'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes'          => ['sometimes', 'nullable', 'string'],
        ]);
        $actorId    = $request->user()?->id ? (string) $request->user()->id : null;
        $tenantSlug = app(TenantContext::class)->slug;
        try {
            $allocation = $this->equipmentService->recordCostAllocation(
                tenantSlug:    $tenantSlug,
                assetId:       $id,
                projectId:     $validated['project_id'],
                periodStart:   $validated['period_start'],
                periodEnd:     $validated['period_end'],
                allocatedCost: (float)$validated['allocated_cost'],
                currency:      $validated['currency'] ?? 'QAR',
                costCode:      $validated['cost_code'] ?? null,
                notes:         $validated['notes'] ?? null,
                actorId:       $actorId,
            );
            return response()->json($allocation->toArray(), 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
