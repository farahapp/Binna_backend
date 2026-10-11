<?php

declare(strict_types=1);

namespace App\Providers;

use App\Approval\ApprovalService;
use App\Approval\Events\ApprovalGranted;
use App\Approval\Events\ApprovalRejected;
use App\Approval\Listeners\RecordApprovalAuditEntry;
use Illuminate\Support\ServiceProvider;

/**
 * ApprovalServiceProvider — registers Approval module services, migrations, and event listeners.
 *
 * Loads Approval migrations from database/migrations/approval/.
 * These are per-tenant migrations applied when a tenant schema connection is active,
 * following the same pattern as AuditServiceProvider and IamServiceProvider.
 *
 * Registers ApprovalService as a singleton.
 *
 * Registers event listeners:
 *   ApprovalGranted  → RecordApprovalAuditEntry
 *   ApprovalRejected → RecordApprovalAuditEntry
 */
class ApprovalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApprovalService::class);
    }

    public function boot(): void
    {
        // Register Approval migration path so artisan migrate can find them.
        // Follows the same pattern as AuditServiceProvider::boot().
        $this->loadMigrationsFrom(database_path('migrations/approval'));

        // Register event listeners for Approval domain events.
        $dispatcher = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);

        $dispatcher->listen(ApprovalGranted::class, RecordApprovalAuditEntry::class);
        $dispatcher->listen(ApprovalRejected::class, RecordApprovalAuditEntry::class);
    }
}
