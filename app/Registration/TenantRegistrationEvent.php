<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Database\Eloquent\Model;

class TenantRegistrationEvent extends Model
{
    public $timestamps = false;

    protected $connection = 'mysql';

    protected $table = 'tenant_registration_events';

    protected $fillable = [
        'request_id',
        'actor_type',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'message',
        'metadata',
        'created_at',
    ];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}
