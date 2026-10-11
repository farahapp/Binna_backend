<?php
declare(strict_types=1);
namespace App\Providers;

use App\Document\DocumentService;
use App\Document\DocumentStorageService;
use App\Document\Storage\FileStorageProviderInterface;
use App\Document\Storage\LocalStorageProvider;
use Illuminate\Support\ServiceProvider;

/**
 * DocumentServiceProvider — registers Document module services and migrations.
 *
 * Loads Document migrations from database/migrations/document/.
 * Registers DocumentService and DocumentStorageService as singletons.
 * Binds FileStorageProviderInterface to LocalStorageProvider (default).
 *
 * Physical storage technology is an OPEN ARCHITECTURAL ITEM (PHASE 7 §6.8).
 * LocalStorageProvider is the default — no external provider has been selected.
 */
class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentService::class);

        // Bind storage provider interface to LocalStorageProvider (default).
        // Physical storage technology is OPEN ARCHITECTURAL ITEM — no external provider selected.
        $this->app->bind(
            FileStorageProviderInterface::class,
            LocalStorageProvider::class,
        );

        $this->app->singleton(DocumentStorageService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/document'));
    }
}
