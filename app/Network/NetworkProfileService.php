<?php

declare(strict_types=1);

namespace App\Network;

use App\Audit\AuditService;
use App\Procurement\TenantSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * NetworkProfileService — Public Capability for Network Profile management.
 *
 * NETWORK-001 — Network Foundation & Profile
 *
 * Architectural constraints (NETWORK-ARCH-001):
 *  - Backend authority: all business rules enforced here.
 *  - Audit: all state-changing operations logged via AuditService.
 *  - OD-005: No automatic conversion between ERP Supplier and Network Profile.
 *  - Cross-tenant: NetworkProfile lives in global schema; TenantSupplier in tenant schema.
 *  - No direct cross-schema DB coupling — linking is a nullable UUID reference only.
 */
class NetworkProfileService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a new Network Profile.
     *
     * @param array{
     *     company_name: string,
     *     profile_type: string,
     *     region?: string|null,
     *     specializations?: array|null,
     *     contact_email?: string|null,
     *     contact_phone?: string|null,
     *     website?: string|null,
     *     description?: string|null,
     * } $data
     */
    public function createProfile(array $data, string $actorId, string $tenantSlug): NetworkProfile
    {
        $profile = NetworkProfile::create([
            'company_name'           => $data['company_name'],
            'profile_type'           => $data['profile_type'],
            'status'                 => 'draft',
            'region'                 => $data['region'] ?? null,
            'specializations'        => $data['specializations'] ?? null,
            'contact_email'          => $data['contact_email'] ?? null,
            'contact_phone'          => $data['contact_phone'] ?? null,
            'website'                => $data['website'] ?? null,
            'description'            => $data['description'] ?? null,
            'managed_by_tenant_slug' => $tenantSlug,
        ]);

        $this->auditService->logEvent('network.profile.created', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkProfile',
            'subject_id'   => $profile->id,
            'after'        => ['company_name' => $profile->company_name, 'profile_type' => $profile->profile_type],
        ]);

        return $profile;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get a Network Profile by ID.
     *
     * @throws ModelNotFoundException
     */
    public function getProfile(string $id): NetworkProfile
    {
        return NetworkProfile::findOrFail($id);
    }

    /**
     * List Network Profiles with optional filters.
     *
     * E.7 P0 — Metadata isolation:
     *  - scope=mine      → profiles managed by the viewer tenant (full fields).
     *  - scope=directory → active profiles only as public cards (no contact/tenant internals).
     * Default scope is directory to prevent cross-tenant private metadata leakage.
     *
     * @param array{type?: string, status?: string, region?: string, scope?: string} $filters
     */
    public function listProfiles(array $filters, string $viewerTenantSlug): LengthAwarePaginator
    {
        $scope = $filters['scope'] ?? 'directory';
        $query = NetworkProfile::query();

        if ($scope === 'mine') {
            $query->managedByTenant($viewerTenantSlug);
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
        } else {
            // Directory: never expose draft/suspended of other tenants.
            $query->where('status', 'active');
        }

        if (! empty($filters['type'])) {
            $query->byType($filters['type']);
        }

        if (! empty($filters['region'])) {
            $query->byRegion($filters['region']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Public directory card — safe cross-tenant discovery fields only.
     *
     * @return array<string, mixed>
     */
    public function toPublicCard(NetworkProfile $profile): array
    {
        return [
            'id'           => $profile->id,
            'company_name' => $profile->company_name,
            'profile_type' => $profile->profile_type,
            'status'       => $profile->status,
            'region'       => $profile->region,
            // contact_email / contact_phone / website / description /
            // managed_by_tenant_slug intentionally excluded (E.7 P0)
        ];
    }

    /**
     * Full profile for the managing tenant.
     *
     * @return array<string, mixed>
     */
    public function toOwnerArray(NetworkProfile $profile): array
    {
        return $profile->toArray();
    }

    /**
     * Resolve profile for show: full if owned, public card if active foreign, else deny.
     *
     * @return array{mode: string, data: array<string, mixed>}
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException when foreign non-active profile
     */
    public function resolveProfileForViewer(string $id, string $viewerTenantSlug): array
    {
        $profile = $this->getProfile($id);

        if ($profile->managed_by_tenant_slug === $viewerTenantSlug) {
            return ['mode' => 'owner', 'data' => $this->toOwnerArray($profile)];
        }

        if ($profile->isActive()) {
            return ['mode' => 'public', 'data' => $this->toPublicCard($profile)];
        }

        throw new ModelNotFoundException('Network profile not found.');
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Update allowed fields on a Network Profile.
     *
     * @param array{
     *     company_name?: string,
     *     region?: string|null,
     *     specializations?: array|null,
     *     contact_email?: string|null,
     *     contact_phone?: string|null,
     *     website?: string|null,
     *     description?: string|null,
     * } $data
     *
     * @throws ModelNotFoundException
     */
    public function updateProfile(string $id, array $data, string $actorId, string $tenantSlug): NetworkProfile
    {
        $profile = $this->getProfile($id);
        $this->assertManagedByTenant($profile, $tenantSlug);

        $before = $profile->only(['company_name', 'region', 'specializations', 'contact_email', 'contact_phone', 'website', 'description']);

        // Only allow updating safe fields — profile_type and status are not updatable here.
        $allowed = ['company_name', 'region', 'specializations', 'contact_email', 'contact_phone', 'website', 'description'];
        $profile->fill(array_intersect_key($data, array_flip($allowed)));
        $profile->save();

        $this->auditService->logEvent('network.profile.updated', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkProfile',
            'subject_id'   => $profile->id,
            'before'       => $before,
            'after'        => $profile->only($allowed),
        ]);

        return $profile->fresh();
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    /**
     * Activate a Network Profile (draft → active).
     *
     * @throws ModelNotFoundException
     * @throws \InvalidArgumentException if profile is not in draft status
     */
    public function activateProfile(string $id, string $actorId, string $tenantSlug): NetworkProfile
    {
        $profile = $this->getProfile($id);
        $this->assertManagedByTenant($profile, $tenantSlug);

        if (! $profile->isDraft()) {
            throw new \InvalidArgumentException(
                "NetworkProfile [{$id}] cannot be activated from status [{$profile->status}]. Only draft profiles can be activated."
            );
        }

        $profile->status = 'active';
        $profile->save();

        $this->auditService->logEvent('network.profile.activated', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkProfile',
            'subject_id'   => $profile->id,
            'before'       => ['status' => 'draft'],
            'after'        => ['status' => 'active'],
        ]);

        return $profile->fresh();
    }

    // -------------------------------------------------------------------------
    // OD-005 — Optional Linking
    // -------------------------------------------------------------------------

    /**
     * Link a TenantSupplier to a Network Profile (optional, explicit).
     *
     * OD-005: No automatic conversion. Linking is explicit and optional.
     *
     * @throws ModelNotFoundException if NetworkProfile or TenantSupplier not found
     */
    public function linkToTenantSupplier(
        string $networkProfileId,
        string $tenantSupplierId,
        string $actorId,
        string $tenantSlug
    ): void {
        // Verify NetworkProfile exists (global schema)
        $profile = $this->getProfile($networkProfileId);

        // Find TenantSupplier (tenant schema)
        $supplier = TenantSupplier::on('tenant')->findOrFail($tenantSupplierId);

        $supplier->network_profile_id = $networkProfileId;
        $supplier->save();

        $this->auditService->logEvent('network.profile.linked_to_supplier', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'NetworkProfile',
            'subject_id'   => $networkProfileId,
            'after'        => [
                'network_profile_id' => $networkProfileId,
                'tenant_supplier_id' => $tenantSupplierId,
            ],
        ]);
    }

    /**
     * Unlink a TenantSupplier from its Network Profile.
     *
     * OD-005: Unlinking is explicit. Does not delete the Network Profile.
     *
     * @throws ModelNotFoundException if TenantSupplier not found
     */
    public function unlinkFromTenantSupplier(
        string $tenantSupplierId,
        string $actorId,
        string $tenantSlug
    ): void {
        $supplier = TenantSupplier::on('tenant')->findOrFail($tenantSupplierId);

        $previousProfileId = $supplier->network_profile_id;

        $supplier->network_profile_id = null;
        $supplier->save();

        $this->auditService->logEvent('network.profile.unlinked_from_supplier', $actorId, [
            'tenant_slug'  => $tenantSlug,
            'subject_type' => 'TenantSupplier',
            'subject_id'   => $tenantSupplierId,
            'before'       => ['network_profile_id' => $previousProfileId],
            'after'        => ['network_profile_id' => null],
        ]);
    }

    /**
     * Assert that the calling tenant manages this profile.
     *
     * @throws \RuntimeException if the caller is not the managing tenant
     */
    private function assertManagedByTenant(NetworkProfile $profile, string $tenantSlug): void
    {
        if ($profile->managed_by_tenant_slug !== $tenantSlug) {
            throw new \RuntimeException('You are not authorized to manage this Network Profile.');
        }
    }
}
