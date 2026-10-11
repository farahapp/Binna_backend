<?php
declare(strict_types=1);
namespace App\BI;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dashboard — BI/Reporting Dashboard configuration.
 * TASK-015A — BI Domain/Data Foundation
 * A1 MOD-18 — CAP-150 Dashboards & Reporting
 * MOD-18 is a PROJECTION (Read-Only Downstream) — owns Dashboard config only.
 * dashboard_type: financial|project|hr|equipment|inventory|commercial|custom
 * status: active|inactive
 */
class Dashboard extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bi_dashboards';
    protected $fillable = [
        'name', 'description', 'dashboard_type', 'config',
        'status', 'tenant_slug', 'created_by',
    ];
    protected $attributes = [
        'status' => 'active',
        'dashboard_type' => 'custom',
    ];
    protected $casts = [
        'config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function isActive(): bool { return $this->status === 'active'; }
    public function isFinancial(): bool { return $this->dashboard_type === 'financial'; }
    public function isProject(): bool { return $this->dashboard_type === 'project'; }
}
