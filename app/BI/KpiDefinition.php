<?php
declare(strict_types=1);
namespace App\BI;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * KpiDefinition — BI/Reporting KPI definition.
 * TASK-015A — BI Domain/Data Foundation
 * A1 MOD-18 — CAP-150 Dashboards & Reporting
 * unit: percentage|currency_qar|count|days
 * status: active|inactive
 */
class KpiDefinition extends Model
{
    use HasUuids;
    protected $connection = 'tenant';
    protected $table = 'bi_kpi_definitions';
    protected $fillable = [
        'name', 'description', 'kpi_key', 'module_scope',
        'calculation_config', 'unit', 'status', 'tenant_slug',
    ];
    protected $attributes = [
        'status' => 'active',
        'unit' => 'count',
    ];
    protected $casts = [
        'calculation_config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function isActive(): bool { return $this->status === 'active'; }
    public function isPercentage(): bool { return $this->unit === 'percentage'; }
    public function isCurrency(): bool { return $this->unit === 'currency_qar'; }
}
