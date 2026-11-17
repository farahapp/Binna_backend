<?php

declare(strict_types=1);

namespace App\Http\Controllers\IAM;

use App\Audit\AuditService;
use App\Foundation\TenantContext;
use App\Http\Controllers\Controller;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Tenant IAM users — list/create/update/role sync within the active tenant schema.
 *
 * Authorization: tenant_admin role OR iam:users:* permissions.
 * Does not change Sanctum / TenantContext / login contracts.
 * Prevents privilege escalation onto tenant_admin by non-admins (SEP-10 spirit).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeActor($request, 'view')) {
            return $deny;
        }

        $search = trim((string) $request->query('search', ''));
        $query = User::on('tenant')->with('roles:id,name')->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $users = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($users->items())->map(fn (User $user) => $this->userPayload($user))->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeActor($request, 'create')) {
            return $deny;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer'],
        ]);

        if (User::on('tenant')->where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => 'A user with this email already exists in the tenant.',
                'errors' => ['email' => ['The email has already been taken.']],
            ], 422);
        }

        $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));
        if ($roleErr = $this->guardRoleAssignment($request->user(), $roleIds)) {
            return $roleErr;
        }

        $user = new User;
        $user->setConnection('tenant');
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = Hash::make($data['password']);
        $user->save();

        if ($roleIds !== []) {
            $this->replaceRoles($user, $roleIds);
        }

        $this->audit($request, 'iam.user.created', (string) $user->id, [
            'email' => $user->email,
            'role_ids' => $roleIds,
        ]);

        return response()->json(['user' => $this->userPayload($user->fresh(['roles']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->authorizeActor($request, 'update')) {
            return $deny;
        }

        $user = User::on('tenant')->with('roles')->find($id);
        if ($user === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'password' => ['sometimes', 'string', 'min:12', 'confirmed'],
        ]);

        if (isset($data['email'])) {
            $taken = User::on('tenant')
                ->where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();
            if ($taken) {
                return response()->json([
                    'message' => 'A user with this email already exists in the tenant.',
                    'errors' => ['email' => ['The email has already been taken.']],
                ], 422);
            }
            $user->email = $data['email'];
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        $this->audit($request, 'iam.user.updated', (string) $user->id, [
            'fields' => array_keys($data),
        ]);

        return response()->json(['user' => $this->userPayload($user->fresh(['roles']))]);
    }

    public function syncRoles(Request $request, string $id): JsonResponse
    {
        if ($deny = $this->authorizeActor($request, 'assign-roles')) {
            return $deny;
        }

        $user = User::on('tenant')->with('roles')->find($id);
        if ($user === null) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $data = $request->validate([
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer'],
        ]);

        $roleIds = array_values(array_unique(array_map('intval', $data['role_ids'])));
        if ($roleErr = $this->guardRoleAssignment($request->user(), $roleIds)) {
            return $roleErr;
        }

        $before = $user->roles()->pluck('name')->all();
        $roles = Role::on('tenant')->whereIn('id', $roleIds)->get();
        $willHaveAdmin = $roles->contains(fn (Role $role) => $role->name === 'tenant_admin');
        $hadAdmin = in_array('tenant_admin', $before, true);
        if ($hadAdmin && ! $willHaveAdmin) {
            $otherAdmins = User::on('tenant')
                ->whereKeyNot($user->getKey())
                ->whereHas('roles', fn ($q) => $q->where('name', 'tenant_admin'))
                ->count();
            if ($otherAdmins === 0) {
                return response()->json([
                    'message' => 'Cannot remove the last tenant_admin role from the company.',
                    'errors' => ['role_ids' => ['At least one tenant_admin must remain.']],
                ], 422);
            }
        }

        $this->replaceRoles($user, $roleIds);
        $after = $user->roles()->pluck('name')->all();

        $this->audit($request, 'iam.user.roles_synced', (string) $user->id, [
            'before' => $before,
            'after' => $after,
        ]);

        return response()->json(['user' => $this->userPayload($user->fresh(['roles']))]);
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function guardRoleAssignment(?User $actor, array $roleIds): ?JsonResponse
    {
        if ($actor === null) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($roleIds === []) {
            return null;
        }

        $roles = Role::on('tenant')->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count($roleIds)) {
            return response()->json([
                'message' => 'One or more roles were not found in this tenant.',
                'errors' => ['role_ids' => ['Invalid role id.']],
            ], 422);
        }

        $assignsAdmin = $roles->contains(fn (Role $role) => $role->name === 'tenant_admin');
        if ($assignsAdmin && ! $actor->hasRole('tenant_admin')) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Only tenant_admin may assign the tenant_admin role.',
            ], 403);
        }

        return null;
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function replaceRoles(User $user, array $roleIds): void
    {
        $user->roles()->detach();
        foreach ($roleIds as $roleId) {
            $role = Role::on('tenant')->find($roleId);
            if ($role !== null) {
                $user->assignRole($role);
            }
        }
    }

    private function authorizeActor(Request $request, string $action): ?JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $permission = match ($action) {
            'view' => 'iam:users:view',
            'create' => 'iam:users:create',
            'update' => 'iam:users:update',
            'assign-roles' => 'iam:users:assign_roles',
            default => 'iam:users:view',
        };

        $authorized = $actor->hasRole('tenant_admin') || $actor->hasPermission($permission);
        if (! $authorized) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return null;
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])->values()->all(),
        ];
    }

    private function audit(Request $request, string $event, string $subjectId, array $after): void
    {
        $tenantSlug = app(TenantContext::class)->slug;
        $actor = $request->user();
        $this->auditService->logEvent(
            $event,
            $actor ? (string) $actor->id : null,
            [
                'tenant_slug' => $tenantSlug,
                'subject_type' => User::class,
                'subject_id' => $subjectId,
                'before' => null,
                'after' => $after,
            ]
        );
    }
}
