<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DailyReport — Daily progress report for a Project.
 *
 * Architectural constraints (TASK-005A / PHASE 6):
 *  - Owned by Projects module.
 *  - Lives in the per-tenant schema (connection = 'tenant') — OD-001.
 *  - submitted_by is a UUID reference to IAM User — no cross-schema FK.
 *
 * TASK-005A — Project Domain/Data Foundation
 */
class DailyReport extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'daily_reports';

    protected $fillable = [
        'project_id',
        'tenant_slug',
        'report_date',
        'submitted_by',
        'weather',
        'work_done',
        'issues',
        'progress_percentage',
    ];

    protected $attributes = [
        'progress_percentage' => 0,
    ];

    protected $casts = [
        'report_date'         => 'date',
        'progress_percentage' => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
