<?php
declare(strict_types=1);
namespace App\Notification;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NotificationPreference — Per-tenant user notification preference.
 *
 * Stores whether a user has enabled a specific notification type on a specific channel.
 * F-13 (UNRESOLVED): UUID follows HasUuids convention.
 */
class NotificationPreference extends Model
{
    use HasUuids;

    protected $connection = 'tenant';
    protected $table = 'notification_preferences';

    protected $fillable = [
        'tenant_slug',
        'user_id',
        'notification_type',
        'channel',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
