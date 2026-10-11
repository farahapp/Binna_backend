<?php

declare(strict_types=1);

namespace App\IAM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role — IAM domain entity.
 *
 * Lives in the per-tenant schema.
 */
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'name',
        'guard_name',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_has_permissions',
            'role_id',
            'permission_id'
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'model_has_roles',
            'role_id',
            'model_id'
        )->wherePivot('model_type', User::class);
    }

    public function givePermission(Permission $permission): void
    {
        $this->permissions()->attach($permission->id);
    }
}
