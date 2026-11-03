<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\Tenant;
use App\Foundation\TenantContextResolver;
use App\IAM\Role;
use App\IAM\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * TenantCreateAdmin — creates the first admin user for an existing active tenant.
 *
 * Usage (production — interactive password prompt):
 *   php artisan tenant:create-admin --slug=acme --email=admin@acme.com --name="Admin User" --confirm
 *
 * Safety guarantees:
 *   - CLI-only: cannot be called from HTTP context.
 *   - Requires --confirm flag in production (APP_ENV=production).
 *   - Password is prompted securely via $this->secret() — never passed as argument.
 *   - Uses the runtime 'tenant' connection (DML only — no DDL).
 *   - Switches to the correct tenant schema before any write.
 *   - Tenant must be 'active' — refuses to operate on pending/inactive tenants.
 *   - Idempotent: refuses if a user with the given email already exists.
 *   - Creates role 'company-admin' with guard 'api' if not present.
 *   - No default/hardcoded passwords — operator must supply one interactively.
 *   - No secrets printed to output.
 *
 * SPEC-FA-001 — First Admin Bootstrap (tenant-level).
 */
class TenantCreateAdmin extends Command
{
    protected $signature = 'tenant:create-admin
                            {--slug=     : Tenant slug (must be active)}
                            {--email=    : Admin user email address}
                            {--name=     : Admin user display name}
                            {--confirm   : Required in production to prevent accidental runs}';

    protected $description = 'Create the first admin user for an existing active tenant (production-safe)';

    /** Role name assigned to the first admin. */
    private const ADMIN_ROLE = 'company-admin';

    /** Minimum password length. */
    private const MIN_PASSWORD_LENGTH = 12;

    public function handle(TenantContextResolver $resolver): int
    {
        // ── 1. Collect and validate inputs ───────────────────────────────────

        $slug  = trim((string) $this->option('slug'));
        $email = trim((string) $this->option('email'));
        $name  = trim((string) $this->option('name'));

        if ($slug === '') {
            $this->error('--slug is required.');
            return self::FAILURE;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--email is required and must be a valid email address.');
            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('--name is required.');
            return self::FAILURE;
        }

        // ── 2. Production confirmation guard ─────────────────────────────────

        if (app()->environment('production') && ! $this->option('confirm')) {
            $this->error('Pass --confirm to run this command in production.');
            return self::FAILURE;
        }

        // ── 3. Resolve tenant — must be active ───────────────────────────────

        $context = $resolver->tryResolve($slug);

        if ($context === null) {
            $this->error("Tenant [{$slug}] not found.");
            return self::FAILURE;
        }

        if (! $context->isActive()) {
            $this->error("Tenant [{$slug}] is not active (status={$context->status}). Cannot create admin.");
            return self::FAILURE;
        }

        // ── 4. Switch 'tenant' connection to this tenant's schema ─────────────

        $schemaName = $context->schemaName;
        Config::set('database.connections.tenant.database', $schemaName);
        DB::purge('tenant');
        DB::reconnect('tenant');

        // ── 5. Check for existing user (idempotency guard) ────────────────────

        $existing = User::on('tenant')->where('email', $email)->first();
        if ($existing !== null) {
            $this->error("A user with email [{$email}] already exists in tenant [{$slug}].");
            return self::FAILURE;
        }

        // ── 6. Prompt for password securely ───────────────────────────────────

        $password = $this->secret(
            "Enter password for [{$email}] (min " . self::MIN_PASSWORD_LENGTH . ' chars):'
        );

        if ($password === null || strlen((string) $password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
            return self::FAILURE;
        }

        $confirm = $this->secret('Confirm password:');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        // ── 7. Create user + role in a transaction ────────────────────────────

        DB::connection('tenant')->transaction(function () use ($email, $name, $password): void {

            $user = User::on('tenant')->create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make((string) $password),
            ]);

            $role = Role::on('tenant')->firstOrCreate(
                ['name' => self::ADMIN_ROLE],
                ['guard_name' => 'api']
            );

            $user->assignRole($role);
        });

        $this->info("✅ User [{$email}] created and assigned role [" . self::ADMIN_ROLE . "].");
        $this->info("✅ Admin user ready for tenant [{$slug}].");
        $this->line("   Email : {$email}");
        $this->line("   Role  : " . self::ADMIN_ROLE);
        $this->line("   Schema: {$schemaName}");

        return self::SUCCESS;
    }
}
