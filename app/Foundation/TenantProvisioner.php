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
 *   3. Apply the per-tenant baseline migration.
 *   4. Record the migration in tenant_migrations.
 *   5. Set status = active.
 *
 * schema_name is ALWAYS derived server-side from the slug.
 * The client MUST NOT supply schema_name.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly TenantBaselineMigrator $baselineMigrator,
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
            // Step 2 — Create the MySQL database/schema.
            $this->createSchema($schemaName);

            // Step 3 & 4 — Apply baseline migration and record it.
            $this->baselineMigrator->run($tenant);

            // Step 5 — Activate the tenant.
            $tenant->status = 'active';
            $tenant->save();
        } catch (\Throwable $e) {
            // Leave tenant in 'pending' so ops can retry or clean up.
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

        if (Tenant::where('slug', $slug)->exists()) {
            throw new \InvalidArgumentException("Tenant slug [{$slug}] is already taken.");
        }
    }

    /**
     * CREATE DATABASE IF NOT EXISTS `{schemaName}` using the shared connection.
     * The schema name is validated server-side before use.
     */
    private function createSchema(string $schemaName): void
    {
        // Extra safety: only allow names matching our server-generated pattern.
        if (! preg_match('/^binaa2_tenant_[a-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException("Unsafe schema name [{$schemaName}] rejected.");
        }

        // Use backtick-quoted identifier — PDO does not support binding identifiers.
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
