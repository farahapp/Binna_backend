<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SwitchToTenantSchemaForJob — Job middleware that switches the 'tenant'
 * database connection to the correct per-tenant schema before a queued
 * job runs, and restores a safe state afterwards.
 *
 * Mirrors the logic of SwitchToTenantSchema (HTTP middleware) for the
 * Queue Worker context, where no HTTP middleware pipeline runs.
 *
 * OD-001: Schema-per-Tenant — the schema_name is ALWAYS resolved from
 * the database via TenantContextResolver; it is never derived from or
 * trusted from the job payload directly.
 *
 * OI-QUEUE-001 — Queue Workers: Tenant Schema Isolation
 * Authorization: OI-QUEUE-001 fix — 2026-10-16
 */
final class SwitchToTenantSchemaForJob
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
    ) {}

    /**
     * Handle the queued job.
     *
     * @param  mixed    $job      The queued job/listener instance.
     * @param  callable $next     The next middleware or the job handler.
     * @param  string   $tenantSlug  The slug carried in the event payload.
     */
    public function handle(mixed $job, callable $next, string $tenantSlug): void
    {
        // Resolve schema_name from DB — never trust the payload's schema directly.
        $context = $this->resolver->tryResolve($tenantSlug);

        if ($context === null) {
            throw new RuntimeException(
                "SwitchToTenantSchemaForJob: tenant not found for slug='{$tenantSlug}'. " .
                "Job aborted to prevent writing to an incorrect schema."
            );
        }

        if (! $context->isActive()) {
            throw new RuntimeException(
                "SwitchToTenantSchemaForJob: tenant '{$tenantSlug}' is not active (status={$context->status}). " .
                "Job aborted."
            );
        }

        $schemaName      = $context->schemaName;
        $previousDatabase = Config::get('database.connections.tenant.database');

        // Point the 'tenant' connection at this tenant's schema.
        $this->applySchema($schemaName);

        try {
            $next($job);
        } finally {
            // Always restore the connection to a neutral state after the job
            // (success or exception) so the next job does not inherit this
            // tenant's schema.
            //
            // We restore to the shared DB (mysql.database) rather than the
            // previous value, because in a long-running worker the "previous"
            // value may itself be a stale tenant schema from an earlier job.
            $sharedDatabase = Config::get('database.connections.mysql.database');
            $this->applySchema($sharedDatabase);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function applySchema(string $databaseName): void
    {
        Config::set('database.connections.tenant.database', $databaseName);
        DB::connection('tenant')->statement("USE `{$databaseName}`");
    }
}
