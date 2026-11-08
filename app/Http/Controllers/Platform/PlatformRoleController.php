<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Audit\PlatformAuditService;
use App\Foundation\PlatformPermission;
use App\Foundation\PlatformRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformRoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PlatformRole::on('mysql')
                ->with('permissions:id,name,description')
                ->withCount('users')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, PlatformAuditService $audit): JsonResponse
    {
        $data = $this->validateRole($request, create: true);
        /** @var \App\Foundation\PlatformUser $actor */
        $actor = $request->user();

        $role = DB::connection('mysql')->transaction(function () use ($actor, $audit, $data): PlatformRole {
            $role = PlatformRole::on('mysql')->create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
            ]);
            $role->permissions()->sync($data['permission_ids'] ?? []);

            $audit->record(
                $actor,
                'platform.role.created',
                'platform_role',
                $role->getKey(),
                null,
                $this->roleSnapshot($role),
            );

            return $role;
        });

        return response()->json([
            'role' => $role->load('permissions:id,name,description')->loadCount('users'),
        ], 201);
    }

    public function update(Request $request, int $id, PlatformAuditService $audit): JsonResponse
    {
        $role = PlatformRole::on('mysql')->findOrFail($id);
        $data = $this->validateRole($request, create: false);
        $this->ensureEditable($role);
        /** @var \App\Foundation\PlatformUser $actor */
        $actor = $request->user();

        DB::connection('mysql')->transaction(function () use ($actor, $audit, $data, $role): void {
            $lockedRole = PlatformRole::on('mysql')->lockForUpdate()->findOrFail($role->getKey());
            $this->ensureEditable($lockedRole);
            $before = $this->roleSnapshot($lockedRole);

            $lockedRole->fill(collect($data)->except('permission_ids')->all())->save();
            $audit->record(
                $actor,
                'platform.role.updated',
                'platform_role',
                $lockedRole->getKey(),
                $before,
                $this->roleSnapshot($lockedRole->fresh()),
            );
        });

        return response()->json([
            'role' => $role->fresh()->load('permissions:id,name,description')->loadCount('users'),
        ]);
    }

    public function syncPermissions(
        Request $request,
        int $id,
        PlatformAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('mysql.platform_permissions', 'id')],
        ]);
        $role = PlatformRole::on('mysql')->findOrFail($id);
        $this->ensureEditable($role);
        /** @var \App\Foundation\PlatformUser $actor */
        $actor = $request->user();

        DB::connection('mysql')->transaction(function () use ($actor, $audit, $data, $role): void {
            $lockedRole = PlatformRole::on('mysql')->lockForUpdate()->findOrFail($role->getKey());
            $this->ensureEditable($lockedRole);
            $before = $lockedRole->permissions()->pluck('platform_permissions.id')->all();
            $requestedPermissionNames = PlatformPermission::on('mysql')
                ->whereIn('id', $data['permission_ids'])
                ->pluck('name')
                ->all();
            if (
                $lockedRole->users()->whereKey($actor->getKey())->exists()
                && array_diff($requestedPermissionNames, $actor->platformPermissionNames()) !== []
            ) {
                abort(403, 'You cannot add permissions to a role assigned to your own account.');
            }

            $lockedRole->permissions()->sync($data['permission_ids']);
            $audit->record(
                $actor,
                'platform.role.permissions_assigned',
                'platform_role',
                $lockedRole->getKey(),
                ['permission_ids' => $before],
                ['permission_ids' => array_values($data['permission_ids'])],
            );
        });

        return response()->json([
            'role' => $role->fresh()->load('permissions:id,name,description')->loadCount('users'),
        ]);
    }

    public function destroy(int $id, PlatformAuditService $audit, Request $request): JsonResponse
    {
        $role = PlatformRole::on('mysql')->findOrFail($id);
        $this->ensureEditable($role);
        if ($role->users()->exists()) {
            abort(422, 'A role assigned to users cannot be deleted.');
        }

        /** @var \App\Foundation\PlatformUser $actor */
        $actor = $request->user();
        DB::connection('mysql')->transaction(function () use ($actor, $audit, $role): void {
            $lockedRole = PlatformRole::on('mysql')->lockForUpdate()->findOrFail($role->getKey());
            $this->ensureEditable($lockedRole);
            if ($lockedRole->users()->exists()) {
                abort(422, 'A role assigned to users cannot be deleted.');
            }

            $before = $this->roleSnapshot($lockedRole);
            $audit->record(
                $actor,
                'platform.role.deleted',
                'platform_role',
                $lockedRole->getKey(),
                $before,
                null,
            );
            $lockedRole->delete();
        });

        return response()->json(status: 204);
    }

    private function validateRole(Request $request, bool $create): array
    {
        return $request->validate([
            'name' => array_merge(
                $create ? ['required'] : ['sometimes', 'required'],
                ['string', 'max:100'],
            ),
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permission_ids' => [$create ? 'sometimes' : 'prohibited', 'array'],
            'permission_ids.*' => ['integer', 'distinct', Rule::exists('mysql.platform_permissions', 'id')],
        ]);
    }

    private function ensureEditable(PlatformRole $role): void
    {
        if ($role->is_system) {
            abort(422, 'System roles cannot be edited or deleted.');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'platform-role';
        $slug = $base;
        $suffix = 1;
        while (PlatformRole::on('mysql')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function roleSnapshot(PlatformRole $role): array
    {
        return [
            'id' => (int) $role->getKey(),
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'permission_ids' => $role->permissions()->pluck('platform_permissions.id')->all(),
        ];
    }
}
