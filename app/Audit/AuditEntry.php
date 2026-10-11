<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * AuditEntry — Immutable audit record.
 *
 * Lives in the per-tenant schema (connection resolved by TenantContext).
 * ID = UUID v4 (TODEC-003).
 *
 * Architectural constraints (NEW-003 / A1 MOD-03 / A2 §2.3):
 *  - Records are WRITE-ONCE: no update, no delete.
 *  - Stores tenant_slug for cross-schema traceability.
 *  - Stores actor_id referencing the IAM User in the same tenant schema.
 *  - Does NOT perform authentication, authorization, or tenant resolution.
 *  - Does NOT emit domain events.
 */
class AuditEntry extends Model
{
    use HasUuids;

    /**
     * Per-tenant connection — resolved by TenantContext (OD-001).
     * The 'tenant' connection is the project-standard per-tenant connection name.
     */
    protected $connection = 'tenant';

    protected $table = 'audit_entries';

    /**
     * Only created_at is used; updated_at is disabled (immutable record).
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'event',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'tenant_slug',
    ];

    protected $casts = [
        'before'     => 'array',
        'after'      => 'array',
        'created_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Immutability — NEW-003 / A1 MOD-03
    // -------------------------------------------------------------------------

    /**
     * Prevent any update to an existing AuditEntry record.
     *
     * @throws LogicException
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('AuditEntry records are immutable and cannot be updated.');
    }

    /**
     * Prevent deletion of an existing AuditEntry record.
     *
     * @throws LogicException
     */
    public function delete(): bool|null
    {
        throw new LogicException('AuditEntry records are immutable and cannot be deleted.');
    }

    /**
     * Prevent force-deletion as well.
     *
     * @throws LogicException
     */
    public function forceDelete(): bool|null
    {
        throw new LogicException('AuditEntry records are immutable and cannot be deleted.');
    }
}
