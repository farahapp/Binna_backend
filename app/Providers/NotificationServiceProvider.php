<?php
declare(strict_types=1);
namespace App\Providers;

use App\Notification\NotificationService;
use App\Notification\Providers\NotificationProviderInterface;
use App\Notification\Providers\NullNotificationProvider;
use Illuminate\Support\ServiceProvider;

/**
 * NotificationServiceProvider — registers Notification module services and migrations.
 *
 * Loads Notification migrations from database/migrations/notification/.
 * Registers NotificationService as a singleton.
 * Binds NotificationProviderInterface to NullNotificationProvider (default).
 *
 * No external provider has been architecturally selected (TASK-002B scope).
 * F-3 (UNRESOLVED): Retry/dead-letter semantics are not implemented.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationService::class);

        // Bind provider interface to NullNotificationProvider (default).
        // No external provider has been architecturally selected.
        $this->app->bind(
            NotificationProviderInterface::class,
            NullNotificationProvider::class,
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/notification'));
    }
}
