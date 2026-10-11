<?php
declare(strict_types=1);
namespace App\Notification;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Notification — Per-tenant notification record.
 *
 * F-3 (UNRESOLVED): Retry/dead-letter semantics are not implemented.
 * F-13 (UNRESOLVED): UUID follows HasUuids convention (UUID v7 in practice).
 */
class Notification extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'notifications';

    protected $fillable = [
        'tenant_slug',
        'notifiable_id',
        'notifiable_type',
        'type',
        'channel',
        'data',
        'status',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function history(): HasMany
    {
        return $this->hasMany(NotificationHistory::class, 'notification_id');
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->read_at = now();
            $this->save();
        }
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
