<?php
declare(strict_types=1);
namespace App\Providers;
use App\BI\ReportingService;
use Illuminate\Support\ServiceProvider;

/**
 * BiServiceProvider — registers BI/Reporting module migrations.
 * Loads BI migrations from database/migrations/bi/.
 * TASK-015A — BI Domain/Data Foundation
 * A1 MOD-18 — CAP-150 Dashboards & Reporting
 */
class BiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped binding: ReportingService is rebuilt for every HTTP request,
        // preventing stale Guard/Request references in long-running PHP processes.
        $this->app->scoped(ReportingService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(
            database_path('migrations/bi')
        );
    }
}
