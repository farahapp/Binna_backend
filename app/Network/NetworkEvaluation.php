<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NetworkEvaluation — Cross-tenant Evaluation entity.
 *
 * NETWORK-004 — Bid Evaluation
 *
 * Lifecycle: not_started → open → completed
 * - completed → open: PROHIBITED
 * - cancellation: PROHIBITED in V1
 *
 * @property string $id
 * @property string $tender_id
 * @property string $status  not_started|open|completed
 * @property string|null $started_by
 * @property string|null $completed_by
 * @property \Carbon\Carbon|null $evaluation_started_at
 * @property \Carbon\Carbon|null $evaluation_completed_at
 * @property string|null $managed_by_tenant_slug
 */
class NetworkEvaluation extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_evaluations';

    protected $fillable = [
        'tender_id', 'status', 'started_by', 'completed_by',
        'evaluation_started_at', 'evaluation_completed_at',
        'managed_by_tenant_slug',
    ];

    protected $attributes = ['status' => 'not_started'];

    protected $casts = [
        'evaluation_started_at'   => 'datetime',
        'evaluation_completed_at' => 'datetime',
    ];

    public function isNotStarted(): bool { return $this->status === 'not_started'; }
    public function isOpen(): bool       { return $this->status === 'open'; }
    public function isCompleted(): bool  { return $this->status === 'completed'; }

    public function notes(): HasMany
    {
        return $this->hasMany(NetworkEvaluationNote::class, 'evaluation_id')->orderBy('created_at');
    }
}
