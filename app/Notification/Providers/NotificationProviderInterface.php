<?php
declare(strict_types=1);
namespace App\Notification\Providers;

use App\Notification\Notification;

/**
 * NotificationProviderInterface — Provider-agnostic notification delivery contract.
 *
 * No external provider has been architecturally selected (TASK-002B scope).
 * Concrete implementations are registered via NotificationServiceProvider.
 */
interface NotificationProviderInterface
{
    /**
     * Deliver a notification via this provider's channel.
     *
     * @param Notification $notification The notification to deliver.
     * @param string       $tenantSlug   The tenant context for this delivery.
     * @return bool True if delivery was attempted successfully.
     */
    public function deliver(Notification $notification, string $tenantSlug): bool;

    /**
     * Return the channel name this provider handles (e.g. 'database', 'log').
     */
    public function channel(): string;
}
