<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TenantProvisioner — creates a new tenant following the NEW-002 lifecycle:
 *
 *   1. Insert Tenant record (status = pending) in shared schema.
 *   2. CREATE DATABASE for the tenant schema (server-generated name).
 *   3. GRANT exact per-schema DML privileges to the runtime user (DEC-MYSQL-001).
 *   4. Verify grants were applied — tenant stays pending if verification fails.
 *   5. Apply the per-tenant baseline migration (schema_info marker — Batch 1).
 *   6. Apply IAM migrations (users, roles, permissions, PATs — Batch 2).
 *   7. Apply all domain migrations (CRM, Projects, Finance, etc. — Batch 3).
 *   8. Record all migrations in tenant_migrations.
 *   9. Set status = active.
 *
 * A tenant is NEVER activated unless ALL steps (2–7) succeed.
 * This guarantees that every active tenant has a login-ready schema with
 * correct, verified MySQL privileges.
 *
 * DEC-MYSQL-001 (Path A) changes:
 *   - createSchema() uses the `mysql_provisioning` connection (not runtime).
 *   - grantAndVerify() grants exact DML-only privileges to the runtime user.
 *   - The runtime `tenant` connection user must NOT have GRANT OPTION or DDL.
 *
 * schema_name is ALWAYS derived server-side from the slug.
 * The client MUST NOT supply schema_name.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly TenantBaselineMigrator $baselineMigrator,
        private readonly IamMigrator            $iamMigrator,
        private readonly TenantSchemaGrantor    $grantor,
        private readonly DomainMigrator         $domainMigrator,
    ) {}

    /**
     * Provision a new tenant.
     *
     * @param  string  $slug  URL-safe identifier (e.g. "acme")
     * @param  string  $name  Human-readable name
     * @return Tenant The newly provisioned, active tenant
     *
     * @throws RuntimeException if provisioning fails
     */
    public function provision(string $slug, string $name): Tenant
    {
        $this->validateSlug($slug);

        // Step 1 — Insert Tenant record (status = pending) in a single write.
        // schema_name is assigned directly on the model instance before save()
        // so that the INSERT includes it, eliminating the NULL schema_name
        // window that existed when two separate saves were used (F-002).
        // Direct assignment is intentional: schema_name is not in $fillable
        // because it must never be accepted from client input.
        $schemaName = Tenant::deriveSchemaName($slug);

        /** @var Tenant $tenant */
        $tenant = new Tenant([
            'slug' => $slug,
            'name' => $name,
            'status' => 'pending',
        ]);
        $tenant->schema_name = $schemaName;
        $tenant->save();

        try {
            // Step 2 — Create the MySQL database/schema via provisioning connection.
            // DEC-MYSQL-001: createSchema uses mysql_provisioning, not the runtime user.
            $this->createSchema($schemaName);

            // Step 3 & 4 — GRANT exact DML privileges to the runtime user and verify.
            // DEC-MYSQL-001: If GRANT or verification fails, tenant stays pending.
            $runtimeUser = config('database.connections.tenant.username')
                ?? config('database.connections.mysql.username')
                ?? env('DB_USERNAME');
            // DEC-MYSQL-002: Use 127.0.0.1 to match the binaa2_runtime MySQL account host.
            // The runtime user is created with @127.0.0.1 (not @%), so granting to @%
            // would fail in MySQL 8.4+ with ERROR 1410 (cannot create user via GRANT).
            $runtimeHost = config('database.connections.mysql.host', '127.0.0.1');

            $this->grantor->grantAndVerify($schemaName, $runtimeUser, $runtimeHost);

            // Step 5 — Apply baseline migration (schema_info — Batch 1) and record it.
            $this->baselineMigrator->run($tenant);

            // Step 6 — Apply IAM migrations (users, roles, permissions, PATs — Batch 2).
            // This MUST succeed before the tenant is activated. If it fails, the tenant
            // remains in 'pending' status and cannot be used for login.
            $this->iamMigrator->run($tenant);

            // Step 7 — Apply all domain migrations (CRM, Projects, Finance, HR, etc. — Batch 3).
            // PROV-DOM-001: This was the missing step that caused new tenants to have only
            // schema_info + IAM tables, with no business-domain tables at all.
            // DomainMigrator iterates database/migrations/{domain}/ directories in dependency
            // order, applies each migration file against the tenant schema via the provisioning
            // connection, and records each result in tenant_migrations.
            // The tenant is NOT activated if any domain migration fails.
            $this->domainMigrator->run($tenant);

            // Step 8 — Activate the tenant only after ALL steps succeed.
            $tenant->status = 'active';
            $tenant->save();
        } catch (\Throwable $e) {
            // Leave tenant in 'pending' so ops can retry or clean up.
            // Both migrators record 'failed' entries in tenant_migrations for diagnostics.
            throw new RuntimeException(
                "Tenant provisioning failed for slug [{$slug}]: ".$e->getMessage(),
                previous: $e,
            );
        }

        return $tenant->fresh();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateSlug(string $slug): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $slug)) {
            throw new \InvalidArgumentException(
                "Invalid tenant slug [{$slug}]. Must be 3–63 lowercase alphanumeric/hyphen characters."
            );
        }

        // DEC-MYSQL-001: Use mysql_provisioning — same reason as TenantProvision::handle().
        // The default mysql connection is not guaranteed to reach the shared schema
        // in production (root@localhost is rejected by MySQL 8.4 auth_socket).
        if (Tenant::on('mysql_provisioning')->where('slug', $slug)->exists()) {
            throw new \InvalidArgumentException("Tenant slug [{$slug}] is already taken.");
        }
    }

    /**
     * CREATE DATABASE IF NOT EXISTS `{schemaName}` using the PROVISIONING connection.
     *
     * DEC-MYSQL-001: Uses mysql_provisioning connection (not runtime user).
     * The schema name is validated server-side before use.
     */
    private function createSchema(string $schemaName): void
    {
        // Extra safety: only allow names matching our server-generated pattern.
        if (! preg_match('/^binaa2_tenant_[a-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException("Unsafe schema name [{$schemaName}] rejected.");
        }

        // Use backtick-quoted identifier — PDO does not support binding identifiers.
        // DEC-MYSQL-001: Use provisioning connection for DDL operations.
        DB::connection('mysql_provisioning')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }
}
