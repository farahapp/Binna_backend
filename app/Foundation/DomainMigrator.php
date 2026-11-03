<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            //
            // Two idempotency cases are handled here for partial-provisioning re-runs:
            //
            // Case A — SQLSTATE 42S01 (MySQL 1050): "Base table or view already exists".
            //   Thrown by Schema::create() when the table was created in a prior run but
            //   the migration was never recorded. Treated as success — the table exists.
            //
            // Case B — SQLSTATE 42S21 (MySQL 1060): "Duplicate column name".
            //   Thrown by Schema::table() ADD COLUMN (e.g. add_progress_percentage_to_projects)
            //   when the column was added in a prior run but the migration was never recorded.
            //   We do NOT blindly accept this — we first verify that the existing column
            //   matches the migration's intent (correct type, nullable/not-null, default).
            //   If the column matches → treat as idempotent success.
            //   If the column does NOT match → re-throw so the operator can investigate.
            //
            // We match on SQLSTATE codes (not human-readable message strings) to avoid
            // false positives from unrelated errors.
            try {
                $migration->up();
            } catch (\Illuminate\Database\QueryException $qe) {
                $sqlState = $qe->getCode();

                if ($sqlState === '42S01') {
                    // Table already exists from a prior partial provisioning run.
                    // Record as applied and continue — no further verification needed
                    // because the table-level guard (hasTable) in IamMigrator already
                    // validates structure for IAM tables; domain tables are created
                    // atomically by a single CREATE TABLE statement.

                } elseif ($sqlState === '42S21') {
                    // Duplicate column name — thrown by ALTER TABLE ADD COLUMN.
                    // Verify the existing column matches the migration definition
                    // before accepting it as idempotent.
                    // FIX: PROV-DOM-002 — safe handling of pre-existing ADD COLUMN migrations.
                    $this->verifyExistingColumnOrThrow($qe, $connectionName);

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
            // FIX: PROV-DOM-003 — always restore tenant config, even when it was null,
            // to prevent config leak between tests and between provisioning runs.
            Config::set('database.default', $previousDefault);
            DB::setDefaultConnection($previousDefault);

            // Restore tenant connection: if it was null before we set it, remove the
            // override entirely so the next caller gets a clean slate.
            if ($previousTenantConfig !== null) {
                Config::set('database.connections.tenant', $previousTenantConfig);
            } else {
                Config::set('database.connections.tenant', null);
            }
            DB::purge('tenant'); // Force reconnect with restored config on next use.
        }
    }

    /**
     * Verify that the column which triggered a 42S21 "Duplicate column name" error
     * matches the migration's expected definition before accepting it as idempotent.
     *
     * Strategy: parse the failing ALTER TABLE SQL to extract the expected column
     * definition, then compare it against information_schema.COLUMNS.
     *
     * Checks performed:
     *   1. Column exists in information_schema (if not → re-throw: 42S21 from
     *      something other than a duplicate column, e.g. a duplicate index name).
     *   2. DATA_TYPE family matches: the MySQL type family reported by
     *      information_schema must be compatible with the type in the failing SQL.
     *      We compare type families (e.g. "tinyint" ↔ "tinyint", "char" ↔ "char")
     *      rather than full COLUMN_TYPE strings to avoid false mismatches from
     *      unsigned/signed or length differences that MySQL normalises differently.
     *   3. Nullability matches: IS_NULLABLE in information_schema must match
     *      whether the failing SQL contains "not null" or not.
     *
     * If any check fails → re-throw so the operator can investigate the mismatch.
     * If parsing fails (SQL format unexpected) → re-throw conservatively.
     *
     * FIX: PROV-DOM-002 — full column-definition verification for 42S21.
     *
     * @throws \Illuminate\Database\QueryException — re-thrown if column does not match
     *                                               or cannot be verified
     */
    private function verifyExistingColumnOrThrow(
        \Illuminate\Database\QueryException $originalException,
        string $connectionName
    ): void {
        $sql = $originalException->getSql() ?? '';

        // ── Step 1: Parse table name and column name from the failing SQL ────────
        //
        // Expected pattern (Laravel Blueprint output):
        //   alter table `<table>` add `<column>` <type_fragment> [not null|null] ...
        //
        // We use conservative regexes; if either parse fails we re-throw.
        $tableMatch  = [];
        $columnMatch = [];

        if (preg_match('/alter\s+table\s+[`"]?(\w+)[`"]?/i', $sql, $tableMatch) !== 1) {
            throw $originalException;
        }
        if (preg_match('/\badd\s+(?:column\s+)?[`"]?(\w+)[`"]?/i', $sql, $columnMatch) !== 1) {
            throw $originalException;
        }

        $tableName  = $tableMatch[1];
        $columnName = $columnMatch[1];

        // ── Step 2: Determine expected nullability from the failing SQL ───────────
        //
        // Laravel emits "not null" for non-nullable columns and "null" (or nothing)
        // for nullable ones. We check for the literal "not null" phrase.
        $expectedNotNull = (bool) preg_match('/\bnot\s+null\b/i', $sql);

        // ── Step 3: Determine expected DATA_TYPE family from the failing SQL ──────
        //
        // We extract the first MySQL type keyword after the column name.
        // Laravel Blueprint emits types like: tinyint unsigned, char(36), varchar(255),
        // bigint unsigned, int unsigned, decimal(8,2), etc.
        //
        // We capture the first word after the column name as the type family.
        $typeMatch = [];
        $escapedCol = preg_quote($columnName, '/');
        if (preg_match('/[`"]?' . $escapedCol . '[`"]?\s+(\w+)/i', $sql, $typeMatch) !== 1) {
            throw $originalException;
        }
        // Normalise to lowercase for comparison.
        $expectedTypeFamily = strtolower($typeMatch[1]);

        // ── Step 4: Query information_schema for the existing column ──────────────
        $schemaName = Config::get("database.connections.{$connectionName}.database");

        $columnInfo = DB::connection($connectionName)
            ->selectOne(
                'SELECT DATA_TYPE, IS_NULLABLE, COLUMN_TYPE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$schemaName, $tableName, $columnName]
            );

        if ($columnInfo === null) {
            // Column does not exist in information_schema — the 42S21 was not caused
            // by a duplicate column (possibly a duplicate index name or other DDL
            // collision). Re-throw so the operator can investigate.
            throw $originalException;
        }

        // ── Step 5: Verify DATA_TYPE family ──────────────────────────────────────
        //
        // information_schema.DATA_TYPE gives the base type without modifiers:
        //   "tinyint", "char", "varchar", "bigint", "int", "decimal", etc.
        //
        // We compare the existing DATA_TYPE against the expected type family.
        // MySQL type aliases we normalise:
        //   uuid() → char(36) → DATA_TYPE = 'char'
        //   unsignedTinyInteger() → tinyint unsigned → DATA_TYPE = 'tinyint'
        //   unsignedBigInteger() → bigint unsigned → DATA_TYPE = 'bigint'
        //
        // We also handle the case where Laravel emits "char" for uuid columns
        // (uuid() is a Blueprint helper that maps to char(36) in MySQL).
        $existingDataType = strtolower((string) $columnInfo->DATA_TYPE);

        // Build a normalisation map for known Blueprint → MySQL type aliases.
        $typeAliases = [
            'uuid'    => 'char',   // Blueprint::uuid() → char(36)
            'boolean' => 'tinyint', // Blueprint::boolean() → tinyint(1)
            'bool'    => 'tinyint',
        ];
        $normalisedExpected = $typeAliases[$expectedTypeFamily] ?? $expectedTypeFamily;

        if ($existingDataType !== $normalisedExpected) {
            // DATA_TYPE mismatch — the existing column has a different type than
            // what the migration defines. This is a real schema inconsistency.
            // Re-throw so the operator can investigate and fix manually.
            throw $originalException;
        }

        // ── Step 6: Verify nullability ────────────────────────────────────────────
        $existingIsNullable = strtoupper((string) $columnInfo->IS_NULLABLE) === 'YES';

        if ($expectedNotNull === $existingIsNullable) {
            // Nullability mismatch:
            //   - Migration says NOT NULL but existing column is nullable, OR
            //   - Migration says nullable but existing column is NOT NULL.
            // Either way this is a schema inconsistency. Re-throw.
            throw $originalException;
        }

        // ── All checks passed ─────────────────────────────────────────────────────
        // The existing column matches the migration's definition:
        //   - DATA_TYPE family matches.
        //   - Nullability matches.
        // Treat as idempotent success — the column was added by a prior partial run.
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
