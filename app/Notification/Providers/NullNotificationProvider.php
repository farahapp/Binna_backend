<?php
declare(strict_types=1);
namespace App\Notification\Providers;

use App\Notification\Notification;
use Illuminate\Support\Facades\Log;

/**
 * NullNotificationProvider — Default provider-agnostic implementation.
 *
 * Logs the notification instead of delivering it externally.
 * Used when no external provider has been architecturally selected.
 *
 * No external provider has been selected (TASK-002B scope).
 * F-3 (UNRESOLVED): Retry/dead-letter semantics are not implemented.
 */
class NullNotificationProvider implements NotificationProviderInterface
{
    public function deliver(Notification $notification, string $tenantSlug): bool
    {
        Log::info('NullNotificationProvider: notification dispatched', [
            'tenant_slug'      => $tenantSlug,
            'notification_id'  => $notification->id,
            'type'             => $notification->type,
            'channel'          => $notification->channel,
            'notifiable_id'    => $notification->notifiable_id,
            'notifiable_type'  => $notification->notifiable_type,
        ]);

        return true;
    }

    public function channel(): string
    {
        return 'database';
    }
}
