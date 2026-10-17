<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProjectParty — A party (client) associated with a Project in a given role.
 *
 * Architectural constraints (CORE-01C):
 *  - Owned by Projects module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - No tenant_id column — isolation is physical (Schema-per-Tenant).
 *  - client_id is a UUID reference to CRM Client — no cross-schema FK.
 *  - tenant_slug stored for cross-schema traceability.
 *  - UNIQUE on (project_id, client_id, role) enforced at DB level.
 *
 * CORE-01C — Project Parties Foundation
 */
class ProjectParty extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'project_parties';

    protected $fillable = [
        'tenant_slug',
        'project_id',
        'client_id',
        'role',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
