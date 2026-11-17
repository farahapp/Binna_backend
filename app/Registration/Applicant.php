<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $connection = 'mysql';

    protected $table = 'registration_applicants';

    protected $fillable = ['email', 'email_verified_at'];

    protected $hidden = ['remember_token'];

    protected $casts = ['email_verified_at' => 'datetime'];

    public function requests(): HasMany
    {
        return $this->hasMany(TenantRegistrationRequest::class, 'applicant_id');
    }
}
