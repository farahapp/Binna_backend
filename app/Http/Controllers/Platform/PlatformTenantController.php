<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\Tenant;
use App\Foundation\TenantSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PlatformTenantController — Tenant management for platform operators.
 *
 * Read-only list + detail + status-change operations on tenants.
 * All routes are protected by auth:sanctum (platform guard) + platform.operator middleware.
 *
 * Routes:
 *   GET    /api/platform/tenants              — list all tenants (paginated, filterable)
 *   GET    /api/platform/tenants/{id}         — get tenant detail + settings
 *   PATCH  /api/platform/tenants/{id}/suspend — suspend an active tenant
 *   PATCH  /api/platform/tenants/{id}/activate — activate a pending/suspended tenant
 *
 * Security invariants:
 *  - All reads/writes target the shared 'mysql' connection (Tenant model).
 *  - schema_name is NEVER returned to the client (hidden on Tenant model).
 *  - No tenant schema is accessed here — platform operators cannot read tenant data.
 *  - Status transitions: pending → active, active → suspended, suspended → active.
 */
class PlatformTenantController extends Controller
{
    /**
     * List all tenants with optional status filter and search.
     *
     * GET /api/platform/tenants
     * Query: status (pending|active|suspended), search (slug/name), per_page (default 20)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['sometimes', 'nullable', Rule::in(['pending', 'active', 'suspended'])],
            'search'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::on('mysql')->select(['id', 'slug', 'name', 'status', 'created_at', 'updated_at']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('slug', 'like', $search)
                  ->orWhere('name', 'like', $search);
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        $tenants = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($tenants);
    }

    /**
     * Get a single tenant's detail including settings.
     *
     * GET /api/platform/tenants/{id}
     */
    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')
            ->select(['id', 'slug', 'name', 'status', 'created_at', 'updated_at'])
            ->findOrFail($id);

        $settings = TenantSetting::on('mysql')
            ->where('tenant_id', $id)
            ->get(['key', 'value']);

        return response()->json([
            'tenant'   => $tenant,
            'settings' => $settings,
        ]);
    }

    /**
     * Suspend an active tenant.
     *
     * PATCH /api/platform/tenants/{id}/suspend
     */
    public function suspend(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);

        if ($tenant->status !== 'active') {
            return response()->json([
                'error' => "Cannot suspend tenant with status [{$tenant->status}]. Only active tenants can be suspended.",
            ], 422);
        }

        $tenant->status = 'suspended';
        $tenant->save();

        return response()->json([
            'message' => "Tenant [{$tenant->slug}] suspended.",
            'tenant'  => ['id' => $tenant->id, 'slug' => $tenant->slug, 'status' => $tenant->status],
        ]);
    }

    /**
     * Activate a pending or suspended tenant.
     *
     * PATCH /api/platform/tenants/{id}/activate
     */
    public function activate(int $id): JsonResponse
    {
        $tenant = Tenant::on('mysql')->findOrFail($id);

        if ($tenant->status === 'active') {
            return response()->json([
                'error' => "Tenant [{$tenant->slug}] is already active.",
            ], 422);
        }

        $tenant->status = 'active';
        $tenant->save();

        return response()->json([
            'message' => "Tenant [{$tenant->slug}] activated.",
            'tenant'  => ['id' => $tenant->id, 'slug' => $tenant->slug, 'status' => $tenant->status],
        ]);
    }
}
