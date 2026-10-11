<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NetworkProfileVerificationAttempt extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_profile_verification_attempts';

    protected $fillable = [
        'verification_id',
        'network_profile_id',
        'attempt_number',
        'status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'decision_reason',
        'evidence',
    ];

    protected $hidden = [
        'decision_reason',
        'reviewed_by',
        'evidence',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'evidence' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];
}
