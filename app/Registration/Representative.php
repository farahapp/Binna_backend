<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Representative extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $connection = 'mysql';

    protected $table = 'registration_representatives';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'approved_by',
        'approved_at',
        'suspended_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(TenantRegistrationRequest::class, 'representative_id');
    }
}
