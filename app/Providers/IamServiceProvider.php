<?php

declare(strict_types=1);

namespace App\Providers;

use App\IAM\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * IamServiceProvider — registers IAM services and migrations.
 *
 * Loads IAM migrations from database/migrations/iam/.
 * These are per-tenant migrations applied by TenantBaselineMigrator.
 *
 * Registers the tenant-scoped PersonalAccessToken model so that Sanctum
 * stores and looks up PATs on the 'tenant' connection (C-002).
 */
class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register IAM migration path so artisan migrate can find them.
        $this->loadMigrationsFrom(database_path('migrations/iam'));

        // Override Sanctum's default PersonalAccessToken model with the
        // tenant-scoped version so all PAT operations use the 'tenant'
        // connection (C-002 / HB-1). This must run during boot so that
        // Sanctum authentication uses the correct model for every request.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
