<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DomainMigrator — applies all per-tenant domain migrations to a freshly
 * provisioned tenant schema and records each one in tenant_migrations
 * (shared schema).
 *
 * This is "Track 2 — Batch 3" as described in NEW-002.
 * It runs immediately after IamMigrator (Batch 2) inside
 * TenantProvisioner::provision(), so a tenant is never activated without
 * a complete, fully-migrated schema covering all business domains.
 *
 * Domain migration directories (relative to database_path('migrations/')):
 *   accounting, approval, audit, bi, commercial, cost_control, crm,
 *   document, equipment, finance, hr, inventory, network, notification,
 *   procurement, projects, subcontracts
 *
 * Design decisions:
 *  - Each migration file is required and instantiated directly — no artisan
 *    subprocess, no mysqldump, no DDL copy from binaa2_shared.
 *  - Migrations are applied in filename-sorted order within each domain,
 *    and domains are applied in dependency order (audit/approval before
 *    business domains, etc.).
 *  - Each migration is guarded: if the target table already exists the
 *    migration is skipped (idempotency for re-runs after partial failure).
 *  - Each migration result is recorded in tenant_migrations (shared schema)
 *    with batch = 3 and status = 'applied' | 'failed'.
 *  - The temporary connection is purged in a finally block to prevent leaks.
 *  - No secrets are logged.
 *
 * PROV-DOM-001 — Domain Migration Gap fix.
 */
class DomainMigrator
{
    /** Batch number — follows IamMigrator's batch 2. */
    private const BATCH = 3;

    /**
     * Domain migration directories in dependency order.
     * Each entry is a subdirectory of database_path('migrations/').
     *
     * Order rationale:
     *   - audit, approval, notification: cross-cutting, no domain FKs
     *   - document: referenced by many domains
     *   - crm: referenced by projects, commercial
     *   - commercial: referenced by projects
     *   - projects: referenced by cost_control, hr, equipment, subcontracts
     *   - cost_control, hr, equipment, inventory, procurement: independent
     *   - finance, accounting: depend on projects/crm for references
     *   - subcontracts: depends on projects
     *   - bi: standalone
     *
     * NOTE: 'network' is intentionally excluded.
     * Network migrations (network_profiles, network_tenders, etc.) use
     * Schema::connection('mysql') explicitly — they belong to the SHARED
     * schema (NETWORK-ARCH-001 / OD-001), not to any per-tenant schema.
     * They are applied once via AppServiceProvider::loadMigrationsFrom()
     * on the shared schema, not per-tenant. Including them here would
     * record misleading 'applied' entries in tenant_migrations for every
     * tenant provisioned, and would re-run shared-schema DDL unnecessarily.
     */
    private const DOMAIN_DIRS = [
        'audit',
        'approval',
        'notification',
        'document',
        'crm',
        'commercial',
        'projects',
        'cost_control',
        'hr',
        'equipment',
        'inventory',
        'procurement',
        'finance',
        'accounting',
        'subcontracts',
        'bi',
        // 'network' excluded — shared schema only (NETWORK-ARCH-001)
    ];

    /**
     * Apply all domain migrations to the given tenant's schema and record results.
     *
     * @throws Throwable — re-thrown after recording 'failed' status for the failing migration
     */
    public function run(Tenant $tenant): void
    {
        $connectionName = $this->buildTenantConnection($tenant->schema_name);

        try {
            $this->applyDomainMigrations($connectionName, $tenant);
        } finally {
            DB::purge($connectionName);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Register a temporary Eloquent connection pointing at the tenant schema.
     * Mirrors IamMigrator::buildTenantConnection() — same pattern,
     * different connection name prefix to avoid collisions.
     */
    private function buildTenantConnection(string $schemaName): string
    {
        $connectionName = 'tenant_domain_' . $schemaName;

        $base = Config::get('database.connections.mysql_provisioning');
        Config::set("database.connections.{$connectionName}", array_merge($base, [
            'database' => $schemaName,
        ]));

        return $connectionName;
    }

    /**
     * Iterate over all domain directories in order, collect migration files,
     * sort them by filename, and apply each one.
     */
    private function applyDomainMigrations(string $connectionName, Tenant $tenant): void
    {
        foreach (self::DOMAIN_DIRS as $domain) {
            $dir = database_path('migrations/' . $domain);

            if (! is_dir($dir)) {
                // Domain directory does not exist — skip silently.
                // This allows the provisioner to work even if a domain is
                // not yet present in the codebase.
                continue;
            }

            // Collect .php files and sort by filename (timestamp-based ordering).
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
            if ($files === false || empty($files)) {
                continue;
            }
            sort($files);

            foreach ($files as $file) {
                $this->applyMigrationFile($file, $connectionName, $tenant, $domain);
            }
        }
    }

    /**
     * Require and run a single migration file against the tenant connection.
     * Records the result in tenant_migrations.
     *
     * Domain migrations use the Schema and DB facades directly (no setConnection()
     * method on anonymous-class migrations). We temporarily override the default
     * database connection to point at the tenant schema, run up(), then restore.
     *
     * @throws Throwable — re-thrown after recording 'failed' status
     */
    private function applyMigrationFile(
        string $file,
        string $connectionName,
        Tenant $tenant,
        string $domain
    ): void {
        // Derive the logical migration name from the filename (no extension, no path).
        $migrationName = $domain . '/' . pathinfo($file, PATHINFO_FILENAME);

        // Check if already recorded as applied — idempotency for re-runs.
        $alreadyApplied = TenantMigration::where('tenant_id', $tenant->id)
            ->where('migration', $migrationName)
            ->where('status', 'applied')
            ->exists();

        if ($alreadyApplied) {
            return;
        }

        // Save the current default and tenant connection configs so we can restore them.
        $previousDefault       = Config::get('database.default');
        $previousTenantConfig  = Config::get('database.connections.tenant');

        try {
            // Temporarily redirect BOTH the default connection AND the named 'tenant'
            // connection to the provisioning tenant schema.
            //
            // Why both?
            //   - Early domain migrations (audit, crm, projects, etc.) use Schema::create()
            //     with no explicit connection — they pick up the default connection.
            //   - Later domain migrations (cost_control, hr, finance, etc.) use
            //     Schema::connection('tenant')->create() explicitly.
            //   - We must redirect both so all migration styles target the correct schema.
            //
            // The provisioning connection credentials are used (not the runtime 'tenant'
            // credentials) because DDL requires the provisioning account.
            $tenantSchemaConfig = Config::get("database.connections.{$connectionName}");

            Config::set('database.default', $connectionName);
            DB::setDefaultConnection($connectionName);

            Config::set('database.connections.tenant', $tenantSchemaConfig);
            DB::purge('tenant'); // Force reconnect with new config on next use.

            // Require the migration file and get the Migration instance.
            // Domain migrations are anonymous classes — they do NOT have setConnection().
            // PHP caches require return values, so the same object is returned on
            // subsequent calls — this is safe because we only call up() once per
            // migration (guarded by the alreadyApplied check above).
            $migration = require $file;

            // Run the migration's up() method.
            // Wrap in a secondary try/catch for MySQL SQLSTATE 42S01 ("Table already exists").
            // This handles the case where a previous provisioning attempt created the
            // table but failed before recording the migration in tenant_migrations,
            // leaving the schema in a partially-applied state.
            //
            // We match on SQLSTATE '42S01' (the standard SQL state for "base table or
            // view already exists") rather than on the human-readable message string.
            // This avoids false positives from unrelated errors that happen to contain
            // the phrase "already exists" (e.g. MySQL 1061 duplicate key name).
            //
            // NOTE: We do NOT verify column-level schema correctness here. If a table
            // exists with a different structure (e.g. from a manual DDL or a different
            // migration), the 42S01 catch will still record the migration as 'applied'.
            // This is an acceptable trade-off for idempotent re-runs after partial
            // failure — the assumption is that any pre-existing table in a tenant schema
            // was created by a prior run of this same migration.
            try {
                $migration->up();
            } catch (\Illuminate\Database\QueryException $qe) {
                // SQLSTATE 42S01: Base table or view already exists (MySQL error 1050).
                // Treat as idempotent success — the table was created by a prior run.
                // Any other SQLSTATE is re-thrown as a real failure.
                if ($qe->getCode() === '42S01') {
                    // Table already exists from a prior partial provisioning run.
                    // Record as applied and continue.
                } else {
                    throw $qe;
                }
            }

            $this->recordMigration($tenant, $migrationName, 'applied');
        } catch (Throwable $e) {
            $this->recordMigrationSafe($tenant, $migrationName, 'failed');
            throw $e;
        } finally {
            // Always restore the original default and tenant connections.
            Config::set('database.default', $previousDefault);
            DB::setDefaultConnection($previousDefault);

            if ($previousTenantConfig !== null) {
                Config::set('database.connections.tenant', $previousTenantConfig);
            }
            DB::purge('tenant'); // Force reconnect with restored config on next use.
        }
    }

    /**
     * Record a migration result in the shared-schema tenant_migrations table.
     */
    private function recordMigration(Tenant $tenant, string $migration, string $status): void
    {
        TenantMigration::create([
            'tenant_id'  => $tenant->id,
            'migration'  => $migration,
            'batch'      => self::BATCH,
            'status'     => $status,
            'applied_at' => now(),
        ]);
    }

    /**
     * Record a migration result, swallowing any exception so that the
     * original error is not masked by a secondary recording failure.
     */
    private function recordMigrationSafe(Tenant $tenant, string $migration, string $status): void
    {
        try {
            $this->recordMigration($tenant, $migration, $status);
        } catch (Throwable) {
            // Swallow — the original exception is more important.
        }
    }
}
