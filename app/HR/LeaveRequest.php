<?php

declare(strict_types=1);

namespace App\HR;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LeaveRequest — Employee leave request with Approval integration.
 *
 * Architectural constraints (TASK-010A):
 *  - Owned by HR module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - approval_request_id is a UUID reference to Approval module — no cross-schema FK (OD-007).
 *  - reviewed_by is a UUID reference to IAM users — no cross-schema FK.
 *  - UUID primary key (HasUuids convention).
 *
 * TASK-010A — HR Domain/Data Foundation
 */
class LeaveRequest extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'leave_requests';

    protected $fillable = [
        'tenant_slug',
        'employee_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_requested',
        'status',
        'reason',
        'rejection_reason',
        'approval_request_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $attributes = [
        'status'         => 'pending',
        'days_requested' => 1,
    ];

    protected $casts = [
        'start_date'     => 'date',
        'end_date'       => 'date',
        'days_requested' => 'decimal:2',
        'reviewed_at'    => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    // Relationships

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // Status helpers

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
}
