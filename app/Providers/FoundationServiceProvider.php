<?php

declare(strict_types=1);

namespace App\Providers;

use App\Foundation\DomainMigrator;
use App\Foundation\IamMigrator;
use App\Foundation\ProvisioningConnectionAccess;
use App\Foundation\ProvisioningMySqlConnection;
use App\Foundation\SwitchToTenantSchemaForJob;
use App\Foundation\TenantBaselineMigrator;
use App\Foundation\TenantContextResolver;
use App\Foundation\TenantProvisioner;
use App\Foundation\TenantSchemaGrantor;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\MySqlConnection;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * FoundationServiceProvider — registers Foundation/SaaS services.
 *
 * Kept minimal: no business logic, no IAM, no Audit.
 */
class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContextResolver::class);
        $this->app->singleton(ProvisioningConnectionAccess::class);

        $this->app->singleton(TenantBaselineMigrator::class);

        // IamMigrator — IAM-PROV-001: registered as singleton so it can be
        // resolved independently (e.g. for idempotent re-runs or testing).
        $this->app->singleton(IamMigrator::class);

        // TenantSchemaGrantor — DEC-MYSQL-001: verifies the external exact-schema grant before activation.
        $this->app->singleton(TenantSchemaGrantor::class);

        // DomainMigrator — PROV-DOM-001: applies all per-tenant domain migrations (Batch 3).
        $this->app->singleton(DomainMigrator::class);

        $this->app->singleton(TenantProvisioner::class, function ($app) {
            return new TenantProvisioner(
                $app->make(TenantBaselineMigrator::class),
                $app->make(IamMigrator::class),
                $app->make(TenantSchemaGrantor::class),
                $app->make(DomainMigrator::class),
            );
        });
    }

    public function boot(): void
    {
        DB::extend('mysql_provisioning', function (array $config, string $name): MySqlConnection {
            $access = $this->app->make(ProvisioningConnectionAccess::class);
            $access->assertAuthorized();
            $mysqlConfig = array_merge($config, ['driver' => 'mysql']);
            $pdo = fn () => $this->app->make(ConnectionFactory::class)
                ->createConnector($mysqlConfig)
                ->connect($mysqlConfig);

            return new ProvisioningMySqlConnection(
                $access,
                $pdo,
                $config['database'] ?? '',
                $config['prefix'] ?? '',
                $config,
            );
        });

        $access = $this->app->make(ProvisioningConnectionAccess::class);
        foreach ([JobProcessing::class, ScheduledTaskStarting::class] as $event) {
            Event::listen($event, static fn () => $access->enterRestrictedExecution());
        }
        foreach ([
            JobProcessed::class,
            JobExceptionOccurred::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ] as $event) {
            Event::listen($event, static fn () => $access->leaveRestrictedExecution());
        }

        Event::listen(CommandStarting::class, static function (CommandStarting $event) use ($access): void {
            if ($event->command !== 'tenant:provision-registration') {
                $access->enterRestrictedExecution();
            }
        });
        Event::listen(CommandFinished::class, static function (CommandFinished $event) use ($access): void {
            if ($event->command !== 'tenant:provision-registration') {
                $access->leaveRestrictedExecution();
            }
        });
    }
}
