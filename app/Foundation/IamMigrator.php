<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IamMigrator — applies IAM tables to a freshly provisioned tenant schema.
 *
 * Responsibility: create users, roles, permissions, pivot tables, and
 * personal_access_tokens in the per-tenant schema, then record each
 * migration in tenant_migrations (shared schema).
 *
 * This is "Track 2 — Batch 2" as described in NEW-002.
 * It runs immediately after TenantBaselineMigrator (Batch 1) inside
 * TenantProvisioner::provision(), so a tenant is never activated without
 * a complete, login-ready schema.
 *
 * Design decisions:
 *  - Each table is guarded with hasTable() so the migrator is idempotent
 *    and safe to re-run if provisioning is retried after a partial failure.
 *  - personal_access_tokens uses uuidMorphs('tokenable') to match the
 *    UUID v4 primary key on users (TODEC-003).
 *  - The migrator records one tenant_migrations row per logical migration
 *    name, matching the filenames in migrations/iam/ for traceability.
 *  - The temporary connection is purged in a finally block to prevent leaks.
 */
class IamMigrator
{
    /** Logical migration names recorded in tenant_migrations. */
    private const MIGRATION_USERS = '0001_01_01_000000_create_users_table';
    private const MIGRATION_IAM   = '2026_09_24_000001_create_iam_tables';

    /** Batch number — follows TenantBaselineMigrator's batch 1. */
    private const BATCH = 2;

    /**
     * Apply IAM tables to the given tenant's schema and record the result.
     *
     * @throws \Throwable — re-thrown after recording 'failed' status
     */
    public function run(Tenant $tenant): void
    {
        $connectionName = $this->buildTenantConnection($tenant->schema_name);

        try {
            $this->applyIamTables($connectionName);

            $this->recordMigration($tenant, self::MIGRATION_USERS, 'applied');
            $this->recordMigration($tenant, self::MIGRATION_IAM,   'applied');
        } catch (\Throwable $e) {
            // Record failure for both migrations so ops can identify the gap.
            $this->recordMigrationSafe($tenant, self::MIGRATION_USERS, 'failed');
            $this->recordMigrationSafe($tenant, self::MIGRATION_IAM,   'failed');
            throw $e;
        } finally {
            DB::purge($connectionName);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Register a temporary Eloquent connection pointing at the tenant schema.
     * Mirrors TenantBaselineMigrator::buildTenantConnection() — same pattern,
     * different connection name prefix to avoid collisions.
     */
    private function buildTenantConnection(string $schemaName): string
    {
        $connectionName = 'tenant_iam_' . $schemaName;

        $base = Config::get('database.connections.mysql');
        Config::set("database.connections.{$connectionName}", array_merge($base, [
            'database' => $schemaName,
        ]));

        return $connectionName;
    }

    /**
     * Create all IAM tables in the tenant schema.
     * Each table is guarded with hasTable() for idempotency.
     */
    private function applyIamTables(string $connectionName): void
    {
        $schema = Schema::connection($connectionName);

        // ── users ─────────────────────────────────────────────────────────────
        // UUID v4 primary key (TODEC-003).
        if (! $schema->hasTable('users')) {
            $schema->create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // ── roles ─────────────────────────────────────────────────────────────
        if (! $schema->hasTable('roles')) {
            $schema->create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        // ── permissions ───────────────────────────────────────────────────────
        if (! $schema->hasTable('permissions')) {
            $schema->create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('guard_name')->default('api');
                $table->timestamps();
            });
        }

        // ── model_has_roles ───────────────────────────────────────────────────
        if (! $schema->hasTable('model_has_roles')) {
            $schema->create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->uuid('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
                $table->foreign('role_id')
                    ->references('id')->on('roles')
                    ->onDelete('cascade');
            });
        }

        // ── role_has_permissions ──────────────────────────────────────────────
        if (! $schema->hasTable('role_has_permissions')) {
            $schema->create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
                $table->foreign('permission_id')
                    ->references('id')->on('permissions')
                    ->onDelete('cascade');
                $table->foreign('role_id')
                    ->references('id')->on('roles')
                    ->onDelete('cascade');
            });
        }

        // ── personal_access_tokens ────────────────────────────────────────────
        // Drop Sanctum's auto-published version if it exists (wrong morphs type).
        // Recreate with uuidMorphs to match UUID v4 user IDs (TODEC-003).
        if ($schema->hasTable('personal_access_tokens')) {
            $schema->dropIfExists('personal_access_tokens');
        }

        $schema->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
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
     * Same as recordMigration() but swallows exceptions.
     * Used in the catch block so a recording failure does not mask the
     * original provisioning exception.
     */
    private function recordMigrationSafe(Tenant $tenant, string $migration, string $status): void
    {
        try {
            $this->recordMigration($tenant, $migration, $status);
        } catch (\Throwable) {
            // Best-effort — do not mask the original exception.
        }
    }
}
