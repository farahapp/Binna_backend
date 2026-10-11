<?php
declare(strict_types=1);
namespace App\BI;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReportSchedule — BI/Reporting Report scheduling configuration.
 * TASK-015A — BI Domain/Data Foundation
 * A1 MOD-18 — CAP-150 Dashboards & Reporting
 * frequency: daily|weekly|monthly
 * status: active|paused
 */
class ReportSchedule extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bi_report_schedules';
    protected $fillable = [
        'report_id', 'name', 'frequency', 'next_run_at',
        'last_run_at', 'status', 'config', 'tenant_slug', 'created_by',
    ];
    protected $attributes = [
        'status' => 'active',
        'frequency' => 'monthly',
    ];
    protected $casts = [
        'config' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
    public function isActive(): bool { return $this->status === 'active'; }
    public function isDaily(): bool { return $this->frequency === 'daily'; }
    public function isMonthly(): bool { return $this->frequency === 'monthly'; }
}
