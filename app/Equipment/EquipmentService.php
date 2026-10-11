<?php

declare(strict_types=1);

namespace App\Equipment;

use App\Audit\AuditService;
use App\Equipment\Events\AssetAllocated;
use App\Equipment\Events\AssetDecommissioned;
use App\Equipment\Events\AssetRegistered;
use App\Equipment\Events\AssetReleased;
use App\Equipment\Events\DepreciationRecorded;
use App\Equipment\Events\MaintenanceCompleted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * EquipmentService — Application/Service Layer for Equipment module.
 *
 * Architectural constraints (TASK-011B / A1 MOD-12):
 *  - Implements Equipment/Asset Management use cases (CAP-100 V1.5).
 *  - Dispatches Domain Events: AssetRegistered, AssetAllocated, AssetReleased,
 *    MaintenanceCompleted, AssetDecommissioned, DepreciationRecorded.
 *  - Integrates with AuditService per established conventions.
 *  - Tenant context resolved by caller via tenant_slug — never cross-tenant access.
 *  - No tenant_id — isolation is physical (Schema-per-Tenant) per OD-001.
 *  - No Auth::user() in business logic — actorId passed as parameter.
 *  - MUST NOT implement Equipment Rental (OD-012 IMPL-DEFERRABLE — V2/Future).
 *  - MUST NOT create Accounting Journal Entries directly — fires Events only.
 *  - MUST NOT depend on Procurement or Inventory modules.
 *
 * TASK-011B — Equipment Application/Service Layer
 */
class EquipmentService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // Asset Registration
    // -------------------------------------------------------------------------

    public function registerAsset(
        string $tenantSlug,
        string $assetCode,
        string $name,
        string|null $description = null,
        string|null $category = null,
        string $assetType = 'owned',
        string|null $acquisitionDate = null,
        float|null $acquisitionCost = null,
        string $currency = 'QAR',
        float|null $depreciationRate = null,
        string|null $notes = null,
        string|null $actorId = null,
    ): Asset {
        if (empty($tenantSlug)) throw new InvalidArgumentException('tenant_slug is required.');
        if (empty($assetCode)) throw new InvalidArgumentException('asset_code is required.');
        if (empty($name)) throw new InvalidArgumentException('name is required.');

        if (Asset::where('asset_code', $assetCode)->exists()) {
            throw new InvalidArgumentException("asset_code already exists: {$assetCode}");
        }

        $asset = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $assetCode, $name, $description, $category, $assetType,
            $acquisitionDate, $acquisitionCost, $currency, $depreciationRate, $notes
        ) {
            return Asset::create([
                'tenant_slug'       => $tenantSlug,
                'asset_code'        => $assetCode,
                'name'              => $name,
                'description'       => $description,
                'category'          => $category,
                'asset_type'        => $assetType,
                'status'            => 'active',
                'acquisition_date'  => $acquisitionDate,
                'acquisition_cost'  => $acquisitionCost,
                'currency'          => $currency,
                'current_value'     => $acquisitionCost,
                'depreciation_rate' => $depreciationRate,
                'notes'             => $notes,
            ]);
        });

        $this->auditService->logEvent(
            'equipment.asset.registered',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Asset::class,
                'subject_id'   => $asset->id,
                'after'        => ['asset_code' => $assetCode, 'name' => $name],
            ]
        );

        Event::dispatch(new AssetRegistered($asset->id, $assetCode, $tenantSlug));

        return $asset;
    }

    // -------------------------------------------------------------------------
    // Asset Retrieval
    // -------------------------------------------------------------------------

    public function getAsset(string $assetId): Asset
    {
        $asset = Asset::find($assetId);
        if (!$asset) throw new InvalidArgumentException("Asset not found: {$assetId}");
        return $asset;
    }

    public function listAssets(string $tenantSlug, array $filters = []): Collection
    {
        $query = Asset::where('tenant_slug', $tenantSlug);
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['category'])) $query->where('category', $filters['category']);
        if (isset($filters['project_id'])) $query->where('project_id', $filters['project_id']);
        return $query->orderBy('asset_code')->get();
    }

    // -------------------------------------------------------------------------
    // Project Allocation
    // -------------------------------------------------------------------------

    public function allocateToProject(
        string $tenantSlug,
        string $assetId,
        string $projectId,
        string|null $notes = null,
        string|null $actorId = null,
    ): EquipmentAllocation {
        $asset = $this->getAsset($assetId);

        if (!$asset->isActive()) {
            throw new InvalidArgumentException("Asset {$assetId} is not active (status: {$asset->status}).");
        }
        if (!is_null($asset->project_id)) {
            throw new InvalidArgumentException("Asset {$assetId} is already allocated to project {$asset->project_id}.");
        }

        $allocation = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $asset, $projectId, $notes, $actorId
        ) {
            $allocation = EquipmentAllocation::create([
                'tenant_slug'  => $tenantSlug,
                'asset_id'     => $asset->id,
                'project_id'   => $projectId,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
                'notes'        => $notes,
            ]);

            $asset->update(['project_id' => $projectId, 'assigned_at' => now()]);

            return $allocation;
        });

        $this->auditService->logEvent(
            'equipment.asset.allocated',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Asset::class,
                'subject_id'   => $assetId,
                'after'        => ['project_id' => $projectId],
            ]
        );

        Event::dispatch(new AssetAllocated($assetId, $projectId, $tenantSlug));

        return $allocation;
    }

    public function releaseFromProject(
        string $tenantSlug,
        string $assetId,
        string|null $notes = null,
        string|null $actorId = null,
    ): Asset {
        $asset = $this->getAsset($assetId);

        if (is_null($asset->project_id)) {
            throw new InvalidArgumentException("Asset {$assetId} is not currently allocated to any project.");
        }

        $previousProjectId = $asset->project_id;

        DB::connection('tenant')->transaction(function () use ($asset, $notes) {
            // Close the open allocation record
            EquipmentAllocation::where('asset_id', $asset->id)
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'notes' => $notes]);

            $asset->update(['project_id' => null, 'assigned_at' => null]);
        });

        $this->auditService->logEvent(
            'equipment.asset.released',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Asset::class,
                'subject_id'   => $assetId,
                'after'        => ['previous_project_id' => $previousProjectId],
            ]
        );

        Event::dispatch(new AssetReleased($assetId, $previousProjectId, $tenantSlug));

        return $asset->fresh();
    }

    // -------------------------------------------------------------------------
    // Lifecycle / Status
    // -------------------------------------------------------------------------

    public function sendToMaintenance(
        string $tenantSlug,
        string $assetId,
        string $maintenanceType = 'scheduled',
        string|null $scheduledDate = null,
        string|null $description = null,
        string|null $actorId = null,
    ): MaintenanceRecord {
        $asset = $this->getAsset($assetId);

        if ($asset->isDecommissioned()) {
            throw new InvalidArgumentException("Cannot send decommissioned asset {$assetId} to maintenance.");
        }

        $record = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $asset, $maintenanceType, $scheduledDate, $description
        ) {
            $record = MaintenanceRecord::create([
                'tenant_slug'      => $tenantSlug,
                'asset_id'         => $asset->id,
                'maintenance_type' => $maintenanceType,
                'status'           => 'pending',
                'scheduled_date'   => $scheduledDate,
                'description'      => $description,
            ]);

            $asset->update(['status' => 'under_maintenance']);

            return $record;
        });

        $this->auditService->logEvent(
            'equipment.maintenance.scheduled',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Asset::class,
                'subject_id'   => $assetId,
                'after'        => ['maintenance_record_id' => $record->id, 'type' => $maintenanceType],
            ]
        );

        return $record;
    }

    public function completeMaintenance(
        string $tenantSlug,
        string $maintenanceRecordId,
        float|null $cost = null,
        string|null $performedBy = null,
        string|null $notes = null,
        string|null $actorId = null,
    ): MaintenanceRecord {
        $record = MaintenanceRecord::find($maintenanceRecordId);
        if (!$record) throw new InvalidArgumentException("MaintenanceRecord not found: {$maintenanceRecordId}");

        if ($record->status === 'completed') {
            throw new InvalidArgumentException("Maintenance record {$maintenanceRecordId} is already completed.");
        }

        $asset = $this->getAsset($record->asset_id);

        DB::connection('tenant')->transaction(function () use ($record, $asset, $cost, $performedBy, $notes) {
            $record->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'cost'         => $cost,
                'performed_by' => $performedBy,
                'notes'        => $notes,
            ]);

            $asset->update(['status' => 'active']);
        });

        $this->auditService->logEvent(
            'equipment.maintenance.completed',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => MaintenanceRecord::class,
                'subject_id'   => $maintenanceRecordId,
                'after'        => ['asset_id' => $asset->id, 'cost' => $cost],
            ]
        );

        Event::dispatch(new MaintenanceCompleted($maintenanceRecordId, $asset->id, $tenantSlug));

        return $record->fresh();
    }

    public function decommissionAsset(
        string $tenantSlug,
        string $assetId,
        string|null $notes = null,
        string|null $actorId = null,
    ): Asset {
        $asset = $this->getAsset($assetId);

        if ($asset->isDecommissioned()) {
            throw new InvalidArgumentException("Asset {$assetId} is already decommissioned.");
        }
        if (!is_null($asset->project_id)) {
            throw new InvalidArgumentException("Cannot decommission asset {$assetId} while allocated to a project. Release it first.");
        }

        DB::connection('tenant')->transaction(function () use ($asset, $notes) {
            $asset->update(['status' => 'decommissioned', 'notes' => $notes]);
        });

        $this->auditService->logEvent(
            'equipment.asset.decommissioned',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Asset::class,
                'subject_id'   => $assetId,
                'after'        => ['status' => 'decommissioned'],
            ]
        );

        Event::dispatch(new AssetDecommissioned($assetId, $tenantSlug));

        return $asset->fresh();
    }

    // -------------------------------------------------------------------------
    // Cost Allocation
    // -------------------------------------------------------------------------

    public function recordCostAllocation(
        string $tenantSlug,
        string $assetId,
        string $projectId,
        string $periodStart,
        string $periodEnd,
        float $allocatedCost,
        string $currency = 'QAR',
        string|null $costCode = null,
        string|null $notes = null,
        string|null $actorId = null,
    ): EquipmentCostAllocation {
        $this->getAsset($assetId); // validate exists

        $allocation = DB::connection('tenant')->transaction(function () use (
            $tenantSlug, $assetId, $projectId, $periodStart, $periodEnd,
            $allocatedCost, $currency, $costCode, $notes
        ) {
            return EquipmentCostAllocation::create([
                'tenant_slug'    => $tenantSlug,
                'asset_id'       => $assetId,
                'project_id'     => $projectId,
                'cost_code'      => $costCode,
                'period_start'   => $periodStart,
                'period_end'     => $periodEnd,
                'allocated_cost' => $allocatedCost,
                'currency'       => $currency,
                'notes'          => $notes,
            ]);
        });

        $this->auditService->logEvent(
            'equipment.cost.allocated',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => EquipmentCostAllocation::class,
                'subject_id'   => $allocation->id,
                'after'        => ['asset_id' => $assetId, 'project_id' => $projectId, 'cost' => $allocatedCost],
            ]
        );

        return $allocation;
    }

    // -------------------------------------------------------------------------
    // Depreciation (Event-based — no Journal Entries)
    // -------------------------------------------------------------------------

    public function recordDepreciation(
        string $tenantSlug,
        string $assetId,
        float $depreciationAmount,
        string $currency = 'QAR',
        string|null $actorId = null,
    ): Asset {
        $asset = $this->getAsset($assetId);

        if ($asset->isDecommissioned()) {
            throw new InvalidArgumentException("Cannot record depreciation for decommissioned asset {$assetId}.");
        }

        $newValue = max(0, (float)($asset->current_value ?? 0) - $depreciationAmount);

        DB::connection('tenant')->transaction(function () use ($asset, $newValue) {
            $asset->update(['current_value' => $newValue]);
        });

        $this->auditService->logEvent(
            'equipment.depreciation.recorded',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Asset::class,
                'subject_id'   => $assetId,
                'after'        => ['depreciation_amount' => $depreciationAmount, 'new_value' => $newValue],
            ]
        );

        // Fire event — Accounting module listens and creates Journal Entry
        Event::dispatch(new DepreciationRecorded($assetId, $depreciationAmount, $currency, $tenantSlug));

        return $asset->fresh();
    }

    // -------------------------------------------------------------------------
    // Public Capability: getEquipmentCost (for Cost Control)
    // -------------------------------------------------------------------------

    public function getEquipmentCost(string $projectId, string $periodStart, string $periodEnd): float
    {
        return (float) EquipmentCostAllocation::where('project_id', $projectId)
            ->where('period_start', '>=', $periodStart)
            ->where('period_end', '<=', $periodEnd)
            ->sum('allocated_cost');
    }
}
