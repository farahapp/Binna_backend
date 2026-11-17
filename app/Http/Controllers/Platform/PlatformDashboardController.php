<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use App\Registration\TenantRegistrationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permission-aware Platform Admin dashboard counters.
 * Returns only aggregates the operator is allowed to view. No fabricated stats.
 */
class PlatformDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var PlatformUser $operator */
        $operator = $request->user();
        $permissions = $this->permissionNames($operator);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'sections' => [],
        ];

        if (in_array('platform.tenants.view', $permissions, true) && Schema::connection('mysql')->hasTable('tenants')) {
            $tenants = DB::connection('mysql')->table('tenants');
            $payload['sections']['tenants'] = [
                'total' => (clone $tenants)->count(),
                'active' => (clone $tenants)->where('status', 'active')->count(),
                'suspended' => (clone $tenants)->where('status', 'suspended')->count(),
                'pending' => (clone $tenants)->where('status', 'pending')->count(),
            ];
        }

        if (
            in_array('platform.tenant_registrations.view', $permissions, true)
            && class_exists(TenantRegistrationRequest::class)
            && Schema::connection('mysql')->hasTable('tenant_registration_requests')
        ) {
            $regs = DB::connection('mysql')->table('tenant_registration_requests');
            $payload['sections']['registrations'] = [
                'total' => (clone $regs)->count(),
                'submitted' => (clone $regs)->where('status', 'submitted')->count(),
                'under_review' => (clone $regs)->where('status', 'under_review')->count(),
                'needs_information' => (clone $regs)->where('status', 'needs_information')->count(),
                'approved_pending_provisioning' => (clone $regs)->where('status', 'approved_pending_provisioning')->count(),
                'rejected' => (clone $regs)->where('status', 'rejected')->count(),
            ];
        }

        if (in_array('platform.users.view', $permissions, true)) {
            $users = DB::connection('mysql')->table('platform_users');
            $payload['sections']['users'] = [
                'total' => (clone $users)->count(),
                'active' => (clone $users)->where('is_active', 1)->count(),
                'inactive' => (clone $users)->where('is_active', 0)->count(),
            ];
        }

        if (in_array('platform.roles.view', $permissions, true)) {
            $payload['sections']['roles'] = [
                'total' => DB::connection('mysql')->table('platform_roles')->count(),
                'system' => DB::connection('mysql')->table('platform_roles')->where('is_system', 1)->count(),
                'custom' => DB::connection('mysql')->table('platform_roles')->where('is_system', 0)->count(),
            ];
        }

        if (
            in_array('platform.audit.view', $permissions, true)
            && Schema::connection('mysql')->hasTable('platform_audit_entries')
        ) {
            $payload['sections']['audit'] = [
                'total' => DB::connection('mysql')->table('platform_audit_entries')->count(),
                'latest' => DB::connection('mysql')->table('platform_audit_entries')
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(['id', 'actor_id', 'event', 'subject_type', 'subject_id', 'created_at'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'actor_id' => $row->actor_id,
                        'event' => $row->event,
                        'subject_type' => $row->subject_type,
                        'subject_id' => $row->subject_id,
                        'created_at' => $row->created_at,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return response()->json($payload);
    }

    /** @return list<string> */
    private function permissionNames(PlatformUser $operator): array
    {
        if (! PlatformUser::platformIamSchemaAvailable()) {
            return [];
        }

        return $operator->platformRoles()
            ->with('permissions:id,name')
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }
}
