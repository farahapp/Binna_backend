<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Audit\PlatformAuditService;
use App\Foundation\PlatformRole;
use App\Foundation\PlatformUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlatformUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PlatformUser::on('mysql')
            ->select(['id', 'name', 'email', 'is_active', 'created_at', 'updated_at'])
            ->with('platformRoles:id,name,slug')
            ->orderBy('name');

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(fn ($users) => $users
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        return response()->json($query->paginate($validated['per_page'] ?? 20));
    }

    public function store(Request $request, PlatformAuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('mysql.platform_users', 'email')],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', Rule::exists('mysql.platform_roles', 'id')],
        ]);
        if (($data['is_active'] ?? false) && empty($data['role_ids'])) {
            abort(422, 'An active PlatformUser must be assigned at least one role.');
        }

        /** @var PlatformUser $actor */
        $actor = $request->user();
        $roleIds = array_map('intval', $data['role_ids'] ?? []);
        $this->rejectSystemRoleAssignment($roleIds);
        $user = DB::connection('mysql')->transaction(function () use ($actor, $audit, $data): PlatformUser {
            $user = PlatformUser::on('mysql')->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? false,
            ]);

            if (! empty($data['role_ids'])) {
                $user->platformRoles()->sync(array_map('intval', $data['role_ids']));
                $audit->record(
                    $actor,
                    'platform.user.roles_assigned',
                    'platform_user',
                    $user->getKey(),
                    null,
                    ['role_ids' => array_values($data['role_ids'])],
                );
            }

            $audit->record(
                $actor,
                'platform.user.created',
                'platform_user',
                $user->getKey(),
                null,
                $this->userSnapshot($user),
            );

            return $user;
        });

        return response()->json([
            'user' => $user->load('platformRoles:id,name,slug'),
        ], 201);
    }

    public function update(Request $request, int $id, PlatformAuditService $audit): JsonResponse
    {
        $user = PlatformUser::on('mysql')->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('mysql.platform_users', 'email')->ignore($user->getKey()),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var PlatformUser $actor */
        $actor = $request->user();
        DB::connection('mysql')->transaction(function () use ($actor, $audit, $data, $user): void {
            if (array_key_exists('is_active', $data) && ! (bool) $data['is_active']) {
                $this->lockOwnerRoleForInvariant();
            }
            $lockedUser = PlatformUser::on('mysql')->lockForUpdate()->findOrFail($user->getKey());
            $before = $this->userSnapshot($lockedUser);

            if (
                array_key_exists('is_active', $data)
                && ! (bool) $data['is_active']
                && $lockedUser->is_active
                && $lockedUser->platformRoles()->where('slug', 'platform-owner')->exists()
            ) {
                $this->ensureAnotherActiveOwnerExists($lockedUser);
            }
            if (
                array_key_exists('is_active', $data)
                && (bool) $data['is_active']
                && ! $lockedUser->is_active
                && ! $lockedUser->platformRoles()->exists()
            ) {
                abort(422, 'Assign at least one role before activating this PlatformUser.');
            }

            $lockedUser->fill($data)->save();
            if (array_key_exists('is_active', $data) && ! (bool) $data['is_active']) {
                DB::connection('mysql')
                    ->table('platform_personal_access_tokens')
                    ->where('tokenable_type', PlatformUser::class)
                    ->where('tokenable_id', $lockedUser->getKey())
                    ->delete();
            }
            $audit->record(
                $actor,
                'platform.user.updated',
                'platform_user',
                $lockedUser->getKey(),
                $before,
                $this->userSnapshot($lockedUser->fresh()),
            );
        });

        return response()->json([
            'user' => $user->fresh()->load('platformRoles:id,name,slug'),
        ]);
    }

    public function syncRoles(
        Request $request,
        int $id,
        PlatformAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('mysql.platform_roles', 'id')],
        ]);

        /** @var PlatformUser $actor */
        $actor = $request->user();
        $user = PlatformUser::on('mysql')->findOrFail($id);
        $roleIds = array_map('intval', $data['role_ids']);
        DB::connection('mysql')->transaction(function () use ($actor, $audit, $data, $roleIds, $user): void {
            $this->lockOwnerRoleForInvariant();
            $lockedUser = PlatformUser::on('mysql')->lockForUpdate()->findOrFail($user->getKey());
            if ($lockedUser->is_active && $roleIds === []) {
                abort(422, 'An active PlatformUser must be assigned at least one role.');
            }
            $before = $lockedUser->platformRoles()->pluck('platform_roles.id')->all();
            $this->rejectSystemRoleAssignment($roleIds, array_map('intval', $before));
            if (
                (int) $actor->getKey() === (int) $lockedUser->getKey()
                && array_diff($roleIds, array_map('intval', $before)) !== []
            ) {
                abort(403, 'You cannot assign additional roles to your own account.');
            }
            $ownerRole = PlatformRole::on('mysql')->where('slug', 'platform-owner')->first();
            $ownerRoleId = $ownerRole?->getKey();

            if (
                $ownerRoleId !== null
                && in_array($ownerRoleId, $before, true)
                && ! in_array($ownerRoleId, array_map('intval', $data['role_ids']), true)
                && $lockedUser->is_active
            ) {
                $this->ensureAnotherActiveOwnerExists($lockedUser);
            }

            $lockedUser->platformRoles()->sync(array_map('intval', $data['role_ids']));
            $audit->record(
                $actor,
                'platform.user.roles_assigned',
                'platform_user',
                $lockedUser->getKey(),
                ['role_ids' => $before],
                ['role_ids' => $roleIds],
            );
        });

        return response()->json([
            'user' => $user->fresh()->load('platformRoles:id,name,slug'),
        ]);
    }

    private function ensureAnotherActiveOwnerExists(PlatformUser $user): void
    {
        $anotherOwnerExists = PlatformUser::on('mysql')
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->whereHas('platformRoles', fn ($query) => $query->where('slug', 'platform-owner'))
            ->exists();

        if (! $anotherOwnerExists) {
            abort(422, 'The last active Platform Owner cannot be deactivated or unassigned.');
        }
    }

    private function lockOwnerRoleForInvariant(): void
    {
        PlatformRole::on('mysql')
            ->where('slug', 'platform-owner')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param array<int, int> $roleIds
     * @param array<int, int> $existingRoleIds
     */
    private function rejectSystemRoleAssignment(array $roleIds, array $existingRoleIds = []): void
    {
        $systemRoleIds = PlatformRole::on('mysql')
            ->whereIn('id', array_unique(array_merge($roleIds, $existingRoleIds)))
            ->where('is_system', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $newSystemRoles = array_diff($systemRoleIds, $existingRoleIds);
        $removedSystemRoles = array_diff($systemRoleIds, $roleIds);

        if ($newSystemRoles !== [] || $removedSystemRoles !== []) {
            abort(422, 'System roles can only be assigned through the controlled bootstrap procedure.');
        }
    }

    /**
     * @return array{id: int, name: string, email: string, is_active: bool}
     */
    private function userSnapshot(PlatformUser $user): array
    {
        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
