<?php

declare(strict_types=1);

namespace App\Http\Controllers\IAM;

use App\Audit\AuditService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\IAM\Permission;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RoleController — IAM Role/Permission management.
 *
 * Handles permission-change operations with direct Audit integration.
 *
 * Audit integration (TASK-001C Unit 3 §5):
 *   PATH B: IAM Controller → AuditService::logEvent() → AuditEntry
 *   No Domain Event listener. Audit is NOT a publisher.
 *   tenant_slug comes from the resolved TenantContext (OD-001).
 *   Synchronous logEvent() only. Does NOT implement NEW-003 Async Mandatory Durable Retry.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Assign a permission to a role.
     *
     * POST /api/iam/roles/{roleId}/permissions/{permissionId}
     * Headers: Authorization: Bearer {token}, X-Tenant: {slug}
     *
     * Captures before/after state for the audit record.
     * Models are resolved explicitly on the 'tenant' connection — Route Model Binding
     * is not used because Role/Permission live in the per-tenant schema (OD-001).
     */
    public function assignPermission(Request $request, int|string $roleId, int|string $permissionId): JsonResponse
    {
        // tenant_slug from the resolved TenantContext — authoritative source (OD-001).
        $tenantContext = app(TenantContext::class);
        $tenantSlug    = $tenantContext->slug;

        // Actor = authenticated user performing the change.
        $actorId = $request->user()?->id ? (string) $request->user()->id : null;

        // Resolve models explicitly on the 'tenant' connection (OD-001).
        $role       = Role::on('tenant')->findOrFail($roleId);
        $permission = Permission::on('tenant')->findOrFail($permissionId);

        // Capture BEFORE state.
        $beforePermissions = $role->permissions()->pluck('name')->toArray();

        // Perform the permission change.
        $role->givePermission($permission);

        // Capture AFTER state.
        $afterPermissions = $role->permissions()->pluck('name')->toArray();

        // Permission change audit — Security Observational (NEW-003 §3.4).
        // PATH B: direct logEvent() call — no Domain Event listener.
        $this->auditService->logEvent(
            'iam.permission_assigned',
            $actorId,
            [
                'tenant_slug'  => $tenantSlug,
                'subject_type' => Role::class,
                'subject_id'   => (string) $role->id,
                'before'       => ['permissions' => $beforePermissions],
                'after'        => ['permissions' => $afterPermissions],
            ]
        );

        return response()->json(['message' => 'Permission assigned successfully.']);
    }
}
