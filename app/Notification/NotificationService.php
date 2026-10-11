<?php
declare(strict_types=1);
namespace App\Notification;

use App\Notification\Jobs\SendNotificationJob;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * NotificationService — Public capability for creating and dispatching notifications.
 *
 * Architectural constraints:
 *  - Does NOT perform authentication, authorization, or tenant resolution.
 *  - Tenant identity comes from the caller via $tenantSlug.
 *  - Queued delivery via SendNotificationJob (tenant-aware).
 *  - Provider abstraction via NotificationProviderInterface.
 *  - F-3 (UNRESOLVED): Retry/dead-letter semantics are not implemented.
 *
 * @see App\Notification\Providers\NotificationProviderInterface
 * @see App\Notification\Jobs\SendNotificationJob
 */
class NotificationService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * Create a notification record and dispatch it for queued delivery.
     *
     * @param string               $tenantSlug      The tenant slug (from TenantContext).
     * @param string               $notifiableId    UUID of the notifiable entity (e.g. user).
     * @param string               $notifiableType  Type of the notifiable entity.
     * @param string               $type            Notification type (e.g. 'approval.granted').
     * @param string               $channel         Delivery channel (default: 'database').
     * @param array<string, mixed> $data            Notification payload.
     * @return Notification
     */
    public function send(
        string $tenantSlug,
        string $notifiableId,
        string $notifiableType,
        string $type,
        string $channel = 'database',
        array $data = [],
    ): Notification {
        if (empty($tenantSlug) || empty($notifiableId) || empty($notifiableType) || empty($type)) {
            throw new \InvalidArgumentException(
                'tenantSlug, notifiableId, notifiableType, and type are required.'
            );
        }

        $notification = Notification::create([
            'tenant_slug'     => $tenantSlug,
            'notifiable_id'   => $notifiableId,
            'notifiable_type' => $notifiableType,
            'type'            => $type,
            'channel'         => $channel,
            'data'            => $data,
            'status'          => 'pending',
        ]);

        // Dispatch queued job carrying tenant_slug for tenant context resolution.
        $this->dispatcher->dispatch(new SendNotificationJob(
            notificationId: $notification->id,
            tenantSlug: $tenantSlug,
        ));

        return $notification;
    }

    /**
     * Check if a user has enabled a specific notification type on a channel.
     *
     * @param string $tenantSlug        The tenant slug.
     * @param string $userId            The user UUID.
     * @param string $notificationType  The notification type.
     * @param string $channel           The channel.
     * @return bool True if enabled (default: true if no preference record exists).
     */
    public function isPreferenceEnabled(
        string $tenantSlug,
        string $userId,
        string $notificationType,
        string $channel = 'database',
    ): bool {
        $preference = NotificationPreference::where([
            'tenant_slug'       => $tenantSlug,
            'user_id'           => $userId,
            'notification_type' => $notificationType,
            'channel'           => $channel,
        ])->first();

        // Default to enabled if no preference record exists.
        return $preference === null || $preference->enabled;
    }

    /**
     * Set a user's notification preference for a specific type and channel.
     */
    public function setPreference(
        string $tenantSlug,
        string $userId,
        string $notificationType,
        string $channel,
        bool $enabled,
    ): NotificationPreference {
        return NotificationPreference::updateOrCreate(
            [
                'tenant_slug'       => $tenantSlug,
                'user_id'           => $userId,
                'notification_type' => $notificationType,
                'channel'           => $channel,
            ],
            ['enabled' => $enabled]
        );
    }
}
