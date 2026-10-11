<?php

declare(strict_types=1);

namespace App\IAM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission — IAM domain entity.
 *
 * Lives in the per-tenant schema.
 */
class Permission extends Model
{
    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'guard_name',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_has_permissions',
            'permission_id',
            'role_id'
        );
    }
}
