<?php
declare(strict_types=1);
namespace App\Notification;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * NotificationHistory — Immutable delivery record for a notification.
 *
 * F-3 (UNRESOLVED): Failure/retry/dead-letter semantics are not implemented.
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
class NotificationHistory extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'notification_history';

    protected $fillable = [
        'tenant_slug',
        'notification_id',
        'channel',
        'status',
        'failure_reason',
        'dispatched_at',
        'delivered_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'delivered_at'  => 'datetime',
    ];

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('NotificationHistory records are immutable and cannot be updated.');
    }

    public function delete(): bool|null
    {
        throw new LogicException('NotificationHistory records are immutable and cannot be deleted.');
    }
}
