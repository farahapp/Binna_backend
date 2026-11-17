<?php

declare(strict_types=1);

namespace App\Registration;

use App\Foundation\Tenant;
use App\Foundation\TenantIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantRegistrationRequest extends Model
{
    public const STATUSES = [
        'draft',
        'submitted',
        'under_review',
        'needs_information',
        'approved_pending_provisioning',
        'rejected',
        'provisioned',
    ];

    protected $connection = 'mysql';

    protected $table = 'tenant_registration_requests';

    protected $fillable = [
        'request_number',
        'slug',
        'applicant_id',
        'representative_id',
        'source',
        'status',
        'verification_status',
        'approval_status',
        'applicant_email',
        'name',
        'legal_type',
        'activity_type',
        'phone',
        'responsible_name',
        'responsible_qid',
        'qid_expires_at',
        'owner_consent_at',
        'owner_consent_version',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'tenant_id',
    ];

    protected $hidden = ['responsible_qid'];

    protected $casts = [
        'qid_expires_at' => 'date',
        'owner_consent_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(Representative::class, 'representative_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(TenantIdentifier::class, 'registration_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TenantRegistrationEvent::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TenantRegistrationAttachment::class, 'request_id');
    }
}
