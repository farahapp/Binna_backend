<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\DomainMigrator;
use App\Foundation\Tenant;
use App\Foundation\TenantMigration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TenantApplyDomainMigrations — backfill domain migrations for a legacy tenant
 * that was provisioned before DomainMigrator (PROV-DOM-001) existed.
 *
 * Usage:
 *   php artisan tenant:apply-domain-migrations --slug=auth-smoke --confirm
 *
 * Safety guarantees:
 *   - Requires --slug to be supplied explicitly; no wildcard, no "all tenants".
 *   - Requires --confirm in production to prevent accidental runs.
 *   - Verifies the tenant exists, is active, and its schema is present before
 *     delegating to DomainMigrator.
 *   - Refuses to run if the tenant already has batch=3 records in
 *     tenant_migrations (idempotency guard — prevents double-application).
 *     Pass --force to override this guard (e.g. for partial-failure re-runs).
 *   - Never creates schemas, drops tables, or modifies any other tenant.
 *   - Delegates entirely to DomainMigrator::run() — no migration logic is
 *     duplicated here.
 *   - Never prints secrets, connection strings, or schema internals.
 *
 * DomainMigrator itself is idempotent: it skips tables that already exist
 * (SQLSTATE 42S01) and verifies column definitions before accepting
 * duplicate-column errors (SQLSTATE 42S21 / PROV-DOM-002).
 *
 * NON-INTERACTIVE PRODUCTION CONFIRMATION (tests / CI only):
 *   Set TENANT_APPLY_DOMAIN_CONFIRM=yes before running in APP_ENV=testing.
 *   In a real production shell the operator must pass --confirm.
 *
 * PROV-DOM-001 — Domain Migration Gap fix.
 */
class TenantApplyDomainMigrations extends Command
{
    protected $signature = 'tenant:apply-domain-migrations
                            {--slug=    : Slug of the tenant to migrate (required)}
                            {--confirm  : Required in production to prevent accidental runs}
                            {--force    : Override the batch=3 already-applied guard (re-run after partial failure)}
                            {--dry-run  : Show what would be done without executing migrations}';

    protected $description = 'Backfill domain migrations (PROV-DOM-001) for a legacy tenant';

    /** Env variable for non-interactive production confirmation (tests only). */
    private const CONFIRM_ENV_VAR = 'TENANT_APPLY_DOMAIN_CONFIRM';

    public function handle(DomainMigrator $domainMigrator): int
    {
        // ── 1. Collect and validate --slug ────────────────────────────────────

        $slug = trim((string) $this->option('slug'));

        if ($slug === '') {
            $this->error('--slug is required. Example: --slug=auth-smoke');
            return self::FAILURE;
        }

        // ── 2. Production guard ───────────────────────────────────────────────

        if (app()->environment('production')) {
            if (! $this->productionConfirmed()) {
                $this->error(
                    'Running tenant:apply-domain-migrations in production requires the --confirm flag. '
                    . 'Re-run with --confirm after verifying the target tenant.'
                );
                return self::FAILURE;
            }
        }

        // ── 3. Resolve tenant — must exist ────────────────────────────────────

        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error("Tenant [{$slug}] not found. Aborting.");
            return self::FAILURE;
        }

        // ── 4. Tenant must be active ──────────────────────────────────────────

        if (! $tenant->isActive()) {
            $this->error(
                "Tenant [{$slug}] has status [{$tenant->status}]. "
                . 'Only active tenants may receive domain migrations. Aborting.'
            );
            return self::FAILURE;
        }

        // ── 5. Schema must exist ──────────────────────────────────────────────

        $schemaExists = DB::select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$tenant->schema_name]
        );

        if (empty($schemaExists)) {
            $this->error(
                "Schema for tenant [{$slug}] does not exist in the database. "
                . 'Cannot apply domain migrations to a missing schema. Aborting.'
            );
            return self::FAILURE;
        }

        // ── 6. Idempotency guard — check for existing batch=3 records ─────────

        $existingBatch3Count = TenantMigration::where('tenant_id', $tenant->id)
            ->where('batch', 3)
            ->count();

        if ($existingBatch3Count > 0 && ! $this->option('force')) {
            $this->error(
                "Tenant [{$slug}] already has {$existingBatch3Count} batch=3 migration record(s) "
                . 'in tenant_migrations. Domain migrations appear to have been applied already. '
                . 'Pass --force to override this guard if you need to re-run after a partial failure.'
            );
            return self::FAILURE;
        }

        // ── 7. Dry-run mode ───────────────────────────────────────────────────

        if ($this->option('dry-run')) {
            $this->line('');
            $this->line('  [DRY RUN] tenant:apply-domain-migrations');
            $this->line("    slug        : {$slug}");
            $this->line("    tenant id   : {$tenant->id}");
            $this->line("    status      : {$tenant->status}");
            $this->line("    batch3 recs : {$existingBatch3Count}");
            $this->line('');
            $this->info('Dry run complete. No migrations were applied.');
            return self::SUCCESS;
        }

        // ── 8. Summary before execution ───────────────────────────────────────

        $this->line('');
        $this->line('  tenant:apply-domain-migrations');
        $this->line("    slug        : {$slug}");
        $this->line("    tenant id   : {$tenant->id}");
        $this->line("    status      : {$tenant->status}");
        $this->line("    batch3 recs : {$existingBatch3Count}" . ($existingBatch3Count > 0 ? ' (--force override active)' : ''));
        $this->line('');

        // ── 9. Delegate to DomainMigrator ─────────────────────────────────────
        //
        // DomainMigrator::run() handles:
        //   - Building the provisioning connection for the tenant schema.
        //   - Iterating all domain directories in dependency order.
        //   - Skipping tables that already exist (idempotency).
        //   - Recording each migration in tenant_migrations with batch=3.
        //   - Purging the temporary connection in a finally block.
        //
        // We do NOT duplicate any of that logic here.

        try {
            $domainMigrator->run($tenant);
        } catch (Throwable $e) {
            $this->error('Domain migration failed: ' . $e->getMessage());
            $this->line('  Check tenant_migrations for partial batch=3 records.');
            return self::FAILURE;
        }

        // ── 10. Success ───────────────────────────────────────────────────────

        $appliedCount = TenantMigration::where('tenant_id', $tenant->id)
            ->where('batch', 3)
            ->where('status', 'applied')
            ->count();

        $this->info("Domain migrations applied successfully.");
        $this->line("  tenant  : {$slug}");
        $this->line("  applied : {$appliedCount} migration(s) recorded in tenant_migrations (batch=3)");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether the production confirmation requirement is satisfied.
     *
     * Accepted paths (in order):
     *   1. --confirm flag was passed on the command line.
     *   2. TENANT_APPLY_DOMAIN_CONFIRM=yes env variable is set AND APP_ENV is
     *      'testing' (allows automated tests to exercise the production path
     *      without real interactive input).
     */
    private function productionConfirmed(): bool
    {
        if ($this->option('confirm')) {
            return true;
        }

        $envValue = getenv(self::CONFIRM_ENV_VAR);
        if ($envValue === 'yes' && app()->environment('testing')) {
            return true;
        }

        return false;
    }
}
