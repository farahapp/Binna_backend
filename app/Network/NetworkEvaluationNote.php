<?php

declare(strict_types=1);

namespace App\Network;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NetworkEvaluationNote — Internal evaluator note.
 *
 * NETWORK-004 — Bid Evaluation
 * Visible only to authorized Publisher/Evaluators.
 * Not visible to contractors or competitors.
 *
 * @property string $id
 * @property string $evaluation_id
 * @property string|null $bid_id
 * @property string $author_id
 * @property string $content
 * @property string|null $managed_by_tenant_slug
 */
class NetworkEvaluationNote extends Model
{
    use HasUuids;

    protected $connection = 'mysql';
    protected $table = 'network_evaluation_notes';

    protected $fillable = [
        'evaluation_id', 'bid_id', 'author_id', 'content', 'managed_by_tenant_slug',
    ];
}
