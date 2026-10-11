<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkProfileVerification extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_profile_verifications';

    protected $fillable = [
        'network_profile_id',
        'status',
        'current_attempt_number',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $attributes = [
        'status' => 'unverified',
        'current_attempt_number' => 0,
    ];

    protected $casts = [
        'current_attempt_number' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(NetworkProfileVerificationAttempt::class, 'verification_id');
    }

    public function currentAttempt(): ?NetworkProfileVerificationAttempt
    {
        return $this->attempts()->where('attempt_number', $this->current_attempt_number)->first();
    }
}
