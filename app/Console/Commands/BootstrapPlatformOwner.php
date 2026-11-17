<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\PlatformAuditService;
use App\Foundation\PlatformPermission;
use App\Foundation\PlatformRole;
use App\Foundation\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time Platform Owner bootstrap.
 *
 * Modes:
 *  A) Assign owner role to an EXISTING active PlatformUser (email argument).
 *  B) Create the FIRST PlatformUser + owner role when none exist yet
 *     (--create --name) gated by PLATFORM_BOOTSTRAP_SECRET (session env)
 *     and interactive hidden prompts for secret + password.
 *
 * Passwords and bootstrap secrets are never accepted via CLI options (argv),
 * never written to audit/logs, and must not be committed to project files.
 * Local/testing only for --create.
 */
class BootstrapPlatformOwner extends Command
{
    protected $signature = 'platform:iam:bootstrap-owner
        {email? : Email of the PlatformUser (existing, or new with --create)}
        {--confirm : Confirm the one-time Platform Owner bootstrap}
        {--create : Create the first PlatformUser when the platform has zero users}
        {--name= : Display name when using --create}';

    protected $description = 'One-time bootstrap: create first Platform Owner or assign owner role to an existing operator';

    public function handle(PlatformAuditService $audit): int
    {
        if (! $this->option('confirm')) {
            $this->error('Pass --confirm after verifying the target environment.');

            return self::FAILURE;
        }

        if ($this->option('create')) {
            return $this->createFirstOwner($audit);
        }

        $email = (string) $this->argument('email');
        if ($email === '') {
            $this->error('Email is required unless using --create.');

            return self::FAILURE;
        }

        return $this->assignExistingOwner($audit, $email);
    }

    private function createFirstOwner(PlatformAuditService $audit): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('--create is allowed only in local/testing environments.');

            return self::FAILURE;
        }

        $expected = (string) env('PLATFORM_BOOTSTRAP_SECRET', '');
        if ($expected === '') {
            $this->error('PLATFORM_BOOTSTRAP_SECRET is not set in the process environment.');

            return self::FAILURE;
        }

        $provided = (string) $this->secret('Bootstrap secret (hidden)');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            $this->error('Invalid or missing PLATFORM_BOOTSTRAP_SECRET / bootstrap secret.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');
        if ($email === '' || $name === '') {
            $this->error('Provide email and --name with --create.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Owner password (min 12, hidden)');
        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        if (PlatformUser::on('mysql')->exists()) {
            $this->error('Platform users already exist. Use email assignment mode (without --create).');

            return self::FAILURE;
        }

        if (! $this->assertIamReady()) {
            return self::FAILURE;
        }

        try {
            DB::connection('mysql')->transaction(function () use ($audit, $email, $name, $password): void {
                $this->assertNoOwnerYet();

                $user = PlatformUser::on('mysql')->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                ]);

                $role = $this->ensureOwnerRole();
                $user->platformRoles()->syncWithoutDetaching([$role->getKey()]);

                $audit->record(
                    null,
                    'platform.iam.owner_bootstrapped',
                    'platform_user',
                    $user->getKey(),
                    null,
                    [
                        'role_id' => $role->getKey(),
                        'initiated_by' => 'artisan_create',
                        'email' => $email,
                        // password and bootstrap secret intentionally omitted
                    ],
                );
            });
        } catch (\LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('First Platform Owner created and role assigned. Credentials were not logged.');

        return self::SUCCESS;
    }

    private function assignExistingOwner(PlatformAuditService $audit, string $email): int
    {
        $user = PlatformUser::on('mysql')->where('email', $email)->first();
        if (! $user || ! $user->isActive()) {
            $this->error('An existing active PlatformUser with that email is required.');

            return self::FAILURE;
        }

        if (! $this->assertIamReady()) {
            return self::FAILURE;
        }

        try {
            DB::connection('mysql')->transaction(function () use ($audit, $user): void {
                $this->assertNoOwnerYet();
                $lockedUser = PlatformUser::on('mysql')->lockForUpdate()->find($user->getKey());
                if (! $lockedUser || ! $lockedUser->isActive()) {
                    throw new \LogicException('The selected PlatformUser must remain active during bootstrap.');
                }

                $role = $this->ensureOwnerRole();
                $lockedUser->platformRoles()->syncWithoutDetaching([$role->getKey()]);

                $audit->record(
                    null,
                    'platform.iam.owner_bootstrapped',
                    'platform_user',
                    $lockedUser->getKey(),
                    null,
                    ['role_id' => $role->getKey(), 'initiated_by' => 'artisan'],
                );
            });
        } catch (\LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Platform Owner role assigned. No credentials or tokens were changed.');

        return self::SUCCESS;
    }

    private function assertIamReady(): bool
    {
        $permissions = PlatformPermission::on('mysql')->get();
        if ($permissions->isEmpty()) {
            $this->error('Platform IAM permission catalog is missing. Apply Platform IAM migrations first.');

            return false;
        }
        if (! Schema::connection('mysql')->hasTable('platform_audit_entries')) {
            $this->error('Platform Audit schema is required before bootstrapping the Platform Owner.');

            return false;
        }

        return true;
    }

    private function assertNoOwnerYet(): void
    {
        $role = PlatformRole::on('mysql')->where('slug', 'platform-owner')->lockForUpdate()->first();
        if ($role && ! $role->is_system) {
            throw new \LogicException('The platform-owner slug is already assigned to a non-system role.');
        }
        if ($role && $role->users()->exists()) {
            throw new \LogicException('A Platform Owner is already assigned. Bootstrap is a one-time operation.');
        }
    }

    private function ensureOwnerRole(): PlatformRole
    {
        $permissions = PlatformPermission::on('mysql')->get();
        $role = PlatformRole::on('mysql')->where('slug', 'platform-owner')->lockForUpdate()->first();
        if (! $role) {
            $role = PlatformRole::on('mysql')->create([
                'name' => 'Platform Owner',
                'slug' => 'platform-owner',
                'description' => 'System role with all Platform IAM permissions.',
                'is_system' => true,
            ]);
        }
        $role->permissions()->sync($permissions->modelKeys());

        return $role;
    }
}
