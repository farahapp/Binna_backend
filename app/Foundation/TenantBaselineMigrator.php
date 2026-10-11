<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TenantBaselineMigrator — applies the per-tenant baseline schema to a
 * freshly created tenant database and records the migration in
 * tenant_migrations (shared schema).
 *
 * This is the "Track 2" baseline described in NEW-002.
 * It runs on a temporary dynamic connection that targets the tenant schema.
 */
class TenantBaselineMigrator
{
    private const BASELINE_MIGRATION = '0001_01_01_000000_tenant_baseline';

    /**
     * Run the baseline migration for the given tenant.
     */
    public function run(Tenant $tenant): void
    {
        $connectionName = $this->buildTenantConnection($tenant->schema_name);

        try {
            $this->applyBaseline($connectionName);
            $this->recordMigration($tenant, self::BASELINE_MIGRATION, status: 'applied');
        } catch (\Throwable $e) {
            $this->recordMigration($tenant, self::BASELINE_MIGRATION, status: 'failed');
            throw $e;
        } finally {
            // Purge the temporary connection so it does not leak.
            DB::purge($connectionName);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Register a temporary Eloquent connection pointing at the tenant schema.
     * Returns the connection name.
     */
    private function buildTenantConnection(string $schemaName): string
    {
        $connectionName = 'tenant_baseline_'.$schemaName;

        $base = Config::get('database.connections.mysql');
        Config::set("database.connections.{$connectionName}", array_merge($base, [
            'database' => $schemaName,
        ]));

        return $connectionName;
    }

    /**
     * Create the baseline tables in the tenant schema.
     * At TASK-001A scope this is a structural placeholder — IAM tables
     * (users, roles, etc.) will be added in TASK-001B.
     */
    private function applyBaseline(string $connectionName): void
    {
        $schema = Schema::connection($connectionName);

        // Baseline marker table — confirms the schema was initialised.
        if (! $schema->hasTable('schema_info')) {
            $schema->create('schema_info', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('value')->nullable();
                $table->timestamps();
            });

            DB::connection($connectionName)->table('schema_info')->insert([
                'key' => 'baseline_version',
                'value' => '001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Record the migration result in the shared-schema tenant_migrations table.
     */
    private function recordMigration(Tenant $tenant, string $migration, string $status): void
    {
        TenantMigration::create([
            'tenant_id' => $tenant->id,
            'migration' => $migration,
            'batch' => 1,
            'status' => $status,
            'applied_at' => now(),
        ]);
    }
}
