<?php
declare(strict_types=1);
namespace App\Notification\Jobs;

use App\Foundation\SwitchToTenantSchemaForJob;
use App\Foundation\TenantContextResolver;
use App\Notification\Notification;
use App\Notification\NotificationHistory;
use App\Notification\Providers\NotificationProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SendNotificationJob — Tenant-aware queued notification delivery.
 *
 * Carries tenant_slug to establish the correct tenant context before
 * accessing tenant data. This follows the OD-001 / OD-001-QUEUE pattern.
 *
 * F-3 (UNRESOLVED): Retry/dead-letter/failure semantics are not implemented.
 *   This job uses Laravel's default queue retry behavior.
 *   Dead-letter and custom failure handling are deferred pending F-3 resolution.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param string $notificationId The UUID of the Notification to deliver.
     * @param string $tenantSlug     The tenant slug — required for tenant context resolution.
     */
    public function __construct(
        public readonly string $notificationId,
        public readonly string $tenantSlug,
    ) {}

    /**
     * Job middleware: switch the 'tenant' connection to the correct per-tenant
     * schema before the job runs, and restore it afterwards.
     *
     * tenantSlug is captured at dispatch time from the job's own property.
     * schema_name is resolved from the database by SwitchToTenantSchemaForJob —
     * never derived from the slug directly (OD-001 / OI-QUEUE-001).
     */
    public function middleware(): array
    {
        $tenantSlug = $this->tenantSlug;

        return [
            function (mixed $job, callable $next) use ($tenantSlug): void {
                app(SwitchToTenantSchemaForJob::class)->handle($job, $next, $tenantSlug);
            },
        ];
    }

    /**
     * Execute the job.
     *
     * Resolves tenant context from tenant_slug, then delivers the notification
     * via the registered NotificationProviderInterface.
     *
     * F-3 (UNRESOLVED): No custom retry/dead-letter logic is implemented.
     */
    public function handle(
        TenantContextResolver $tenantContextResolver,
        NotificationProviderInterface $provider,
    ): void {
        // Establish tenant context before accessing tenant data (OD-001-QUEUE).
        // TenantContextResolver::resolve() is the canonical slug-based resolver.
        app()->instance(
            \App\Foundation\TenantContext::class,
            $tenantContextResolver->resolve($this->tenantSlug),
        );

        $notification = Notification::find($this->notificationId);

        if ($notification === null) {
            // Notification was deleted before job ran — nothing to do.
            return;
        }

        $delivered = $provider->deliver($notification, $this->tenantSlug);

        // Record delivery history (immutable).
        NotificationHistory::create([
            'tenant_slug'     => $this->tenantSlug,
            'notification_id' => $notification->id,
            'channel'         => $notification->channel,
            'status'          => $delivered ? 'sent' : 'failed',
            'dispatched_at'   => now(),
            'delivered_at'    => $delivered ? now() : null,
        ]);
    }
}
