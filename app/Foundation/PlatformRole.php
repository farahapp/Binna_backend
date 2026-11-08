<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlatformRole extends Model
{
    protected $connection = 'mysql';

    protected $table = 'platform_roles';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformPermission::class,
            'platform_role_permissions',
            'platform_role_id',
            'platform_permission_id'
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformUser::class,
            'platform_user_roles',
            'platform_role_id',
            'platform_user_id'
        );
    }
}
