<?php

declare(strict_types=1);

namespace App\IAM;

use App\Foundation\TenantContext;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * User — IAM domain entity.
 *
 * Lives in the per-tenant schema (connection resolved by TenantContext).
 * ID = UUID v4 (TODEC-003).
 * Authentication via Laravel Sanctum PAT (HB-1).
 */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;
    use HasUuids;

    protected $connection = 'tenant';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'model_has_roles',
            'model_id',
            'role_id'
        )->wherePivot('model_type', self::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    public function hasPermission(string $permissionName): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
            ->exists();
    }

    public function assignRole(Role $role): void
    {
        $this->roles()->attach($role->id, ['model_type' => self::class]);
    }

    /**
     * tenant_slug — virtual attribute resolved from the active TenantContext.
     *
     * The User model lives in the per-tenant schema; the tenant slug is therefore
     * always the slug of the schema the request is currently operating on.
     * Resolved from the request-scoped TenantContext (set by ResolveTenantContext
     * middleware from the X-Tenant header) — never from user-supplied data.
     *
     * C-002 §26 / OD-001 — tenant identity is backend-authoritative.
     */
    public function getTenantSlugAttribute(): string
    {
        return app(TenantContext::class)->slug;
    }

    /**
     * grantPermission — convenience helper for test setup only.
     *
     * Creates (or retrieves) a Permission and a dedicated Role, attaches the
     * permission to the role via the established givePermission() contract,
     * then assigns the role to this user via assignRole().
     *
     * NOT a production authorization API. Uses the existing IAM contract:
     *   Role::givePermission() + User::assignRole()
     */
    public function grantPermission(string $permissionName): void
    {
        $connection = $this->getConnectionName() ?? 'tenant';

        $permission = Permission::on($connection)->firstOrCreate(
            ['name' => $permissionName],
            ['guard_name' => 'api']
        );

        $role = Role::on($connection)->firstOrCreate(
            ['name' => 'auto-' . $permissionName],
            ['guard_name' => 'api']
        );

        // Attach permission to role only if not already attached (avoid duplicate key)
        if (! $role->permissions()->where('name', $permissionName)->exists()) {
            $role->givePermission($permission);
        }

        // Attach role to user only if not already attached (avoid duplicate key)
        if (! $this->hasRole($role->name)) {
            $this->assignRole($role);
        }
    }
}
