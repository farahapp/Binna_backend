<?php
declare(strict_types=1);
namespace App\BI;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Report — BI/Reporting Report definition.
 * TASK-015A — BI Domain/Data Foundation
 * A1 MOD-18 — CAP-150 Dashboards & Reporting
 * module_scope: finance|accounting|projects|hr|equipment|inventory|procurement|commercial|subcontracts
 * status: active|inactive
 */
class Report extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bi_reports';
    protected $fillable = [
        'name', 'description', 'report_key', 'module_scope',
        'default_filters', 'status', 'tenant_slug', 'created_by',
    ];
    protected $attributes = [
        'status' => 'active',
    ];
    protected $casts = [
        'default_filters' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class, 'report_id');
    }
    public function isActive(): bool { return $this->status === 'active'; }
}
