<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\Tenant;
use App\Foundation\TenantProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * TenantProvision — official Artisan command to provision a new tenant.
 *
 * Usage:
 *   php artisan tenant:provision --slug=acme --name="Acme Corp"
 *
 * Safety guarantees:
 *   - Validates --slug and --name before calling TenantProvisioner.
 *   - Checks for slug conflict and schema_name conflict before delegating.
 *   - In production, requires an explicit --confirm flag (testable without
 *     real interactive input via the TENANT_PROVISION_CONFIRM env variable).
 *   - Never prints secrets, connection strings, or schema internals.
 *   - Does NOT execute provisioning itself; delegates entirely to TenantProvisioner.
 *
 * NON-INTERACTIVE PRODUCTION CONFIRMATION (tests / CI only):
 *   Set TENANT_PROVISION_CONFIRM=yes before running.
 *   This path is only accepted when APP_ENV is NOT 'production' in automated
 *   test runs; in a real production shell the operator must pass --confirm.
 *
 * SLUG REGEX (mirrors TenantProvisioner::validateSlug):
 *   /^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/
 */
class TenantProvision extends Command
{
    protected $signature = 'tenant:provision
                            {--slug=    : URL-safe tenant identifier (3–63 lowercase alphanumeric/hyphen)}
                            {--name=    : Human-readable tenant name}
                            {--confirm  : Required in production to prevent accidental runs}';

    protected $description = 'Provision a new tenant (slug + name required; production requires --confirm)';

    /** Slug validation regex — must match TenantProvisioner::validateSlug. */
    private const SLUG_REGEX = '/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/';

    /** Env variable for non-interactive production confirmation (tests only). */
    private const CONFIRM_ENV_VAR = 'TENANT_PROVISION_CONFIRM';

    public function handle(TenantProvisioner $provisioner): int
    {
        // ── 1. Collect and validate inputs ───────────────────────────────────

        $slug = trim((string) $this->option('slug'));
        $name = trim((string) $this->option('name'));

        if ($slug === '') {
            $this->error('--slug is required.');
            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('--name is required.');
            return self::FAILURE;
        }

        if (! preg_match(self::SLUG_REGEX, $slug)) {
            $this->error(
                "Invalid slug [{$slug}]. Must be 3–63 characters: lowercase letters, digits, hyphens; "
                . 'cannot start or end with a hyphen.'
            );
            return self::FAILURE;
        }

        // ── 2. Pre-flight conflict checks (before touching the provisioner) ──

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Slug [{$slug}] is already taken by an existing tenant.");
            return self::FAILURE;
        }

        $schemaName = Tenant::deriveSchemaName($slug);
        $schemaExists = \Illuminate\Support\Facades\DB::select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$schemaName]
        );
        if (! empty($schemaExists)) {
            $this->error(
                "Schema [{$schemaName}] already exists in the database. "
                . 'Provisioning aborted to prevent data collision.'
            );
            return self::FAILURE;
        }

        // ── 3. Production guard ───────────────────────────────────────────────

        if (app()->environment('production')) {
            if (! $this->productionConfirmed()) {
                $this->error(
                    'Running tenant:provision in production requires the --confirm flag. '
                    . 'Re-run with --confirm after verifying the inputs.'
                );
                return self::FAILURE;
            }
        }

        // ── 4. Summary (no secrets, no connection strings) ───────────────────

        $this->line('');
        $this->line('  Tenant provisioning request:');
        $this->line("    slug : {$slug}");
        $this->line("    name : {$name}");
        $this->line('');

        // ── 5. Delegate to TenantProvisioner ─────────────────────────────────

        try {
            $tenant = $provisioner->provision($slug, $name);
        } catch (InvalidArgumentException $e) {
            // Validation / conflict errors from the provisioner itself.
            $this->error('Provisioning rejected: ' . $e->getMessage());
            return self::FAILURE;
        } catch (RuntimeException $e) {
            // Infrastructure / migration failures.
            $this->error('Provisioning failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // ── 6. Success output (no schema internals, no secrets) ──────────────

        $this->info("Tenant provisioned successfully.");
        $this->line("  id     : {$tenant->id}");
        $this->line("  slug   : {$tenant->slug}");
        $this->line("  name   : {$tenant->name}");
        $this->line("  status : {$tenant->status}");

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
     *   2. TENANT_PROVISION_CONFIRM=yes env variable is set AND APP_ENV is
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
