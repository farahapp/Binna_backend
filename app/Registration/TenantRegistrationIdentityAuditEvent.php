<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Database\Eloquent\Model;

class TenantRegistrationIdentityAuditEvent extends Model
{
    public $timestamps = false;

    protected $connection = 'mysql';

    protected $table = 'registration_identity_audit_events';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'event',
        'metadata',
        'created_at',
    ];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
}
