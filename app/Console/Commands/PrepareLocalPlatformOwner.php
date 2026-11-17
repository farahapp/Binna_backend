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
 * LOCAL ONLY — attach existing PlatformUser to platform-owner + set a new local password.
 *
 * Never accepts password via argv. Never touches Production. Refuses smoke/demo emails.
 */
class PrepareLocalPlatformOwner extends Command
{
    protected $signature = 'platform:iam:prepare-local-owner
        {email? : Existing platform user email (optional if only one non-demo active user)}
        {--confirm : Confirm local-only repair}';

    protected $description = 'Local UAT: set local password (hidden) and attach platform-owner to an existing PlatformUser';

    public function handle(PlatformAuditService $audit): int
    {
        if (! $this->option('confirm')) {
            $this->error('Pass --confirm after verifying this is the local UAT database.');

            return self::FAILURE;
        }

        if (! app()->environment('local', 'testing')) {
            $this->error('Allowed only in local/testing.');

            return self::FAILURE;
        }

        if (! $this->assertLocalSharedDatabase()) {
            return self::FAILURE;
        }

        if (! Schema::connection('mysql')->hasTable('platform_users')
            || ! Schema::connection('mysql')->hasTable('platform_roles')
            || ! Schema::connection('mysql')->hasTable('platform_user_roles')) {
            $this->error('Platform IAM tables missing.');

            return self::FAILURE;
        }

        $user = $this->resolveTargetUser();
        if (! $user) {
            return self::FAILURE;
        }

        $masked = $this->maskEmail((string) $user->email);
        $this->line("Target local PlatformUser id={$user->getKey()} email={$masked} active=".($user->isActive() ? 'yes' : 'no'));
        if (! $this->confirm('Proceed with this account only? (local DB)', false)) {
            $this->warn('Aborted. No changes.');

            return self::FAILURE;
        }

        if (! $user->isActive()) {
            $this->error('Target account is inactive. Activate it first (not done by this command).');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New LOCAL password only (min 12, hidden)');
        $confirmPassword = (string) $this->secret('Confirm LOCAL password (hidden)');
        if (strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }
        if (! hash_equals($password, $confirmPassword)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $role = PlatformRole::on('mysql')->where('slug', 'platform-owner')->first();
        if (! $role || ! $role->is_system) {
            $this->error('System role platform-owner is missing.');

            return self::FAILURE;
        }

        $permissions = PlatformPermission::on('mysql')->get();
        if ($permissions->isEmpty()) {
            $this->error('Platform permission catalog is empty.');

            return self::FAILURE;
        }

        DB::connection('mysql')->transaction(function () use ($audit, $user, $role, $permissions, $password, $masked): void {
            $locked = PlatformUser::on('mysql')->lockForUpdate()->findOrFail($user->getKey());
            $locked->password = $password; // cast: hashed
            $locked->save();

            $role->permissions()->sync($permissions->modelKeys());
            $locked->platformRoles()->syncWithoutDetaching([$role->getKey()]);

            if (Schema::connection('mysql')->hasTable('platform_audit_entries')) {
                $audit->record(
                    null,
                    'platform.iam.local_owner_prepared',
                    'platform_user',
                    $locked->getKey(),
                    null,
                    [
                        'email_masked' => $masked,
                        'role_slug' => 'platform-owner',
                        'permission_count' => $permissions->count(),
                        'initiated_by' => 'artisan_prepare_local_owner',
                        // password intentionally omitted
                    ],
                );
            }
        });

        // Clear sensitive locals
        unset($password, $confirmPassword);

        $fresh = PlatformUser::on('mysql')->findOrFail($user->getKey());
        $hasOwner = $fresh->platformRoles()->where('slug', 'platform-owner')->exists();
        $permCount = $fresh->platformRoles()
            ->where('slug', 'platform-owner')
            ->withCount('permissions')
            ->first()
            ?->permissions_count;

        $this->info('Local owner preparation complete.');
        $this->line('email_masked='.$masked);
        $this->line('has_platform_owner_role='.($hasOwner ? 'YES' : 'NO'));
        $this->line('owner_role_permission_count='.(string) ($permCount ?? 0));
        $this->line('password_set=YES (value not shown)');
        $this->line('Login: http://localhost:3030/platform/login');

        return self::SUCCESS;
    }

    private function assertLocalSharedDatabase(): bool
    {
        $host = (string) config('database.connections.mysql.host');
        $db = (string) config('database.connections.mysql.database');
        $port = (string) config('database.connections.mysql.port');

        $localHosts = ['127.0.0.1', 'localhost', '::1'];
        if (! in_array($host, $localHosts, true)) {
            $this->error("Refusing non-local DB host: {$host}");

            return false;
        }
        if ($db !== 'binaa2_shared') {
            $this->error("Refusing unexpected database [{$db}]; expected binaa2_shared.");

            return false;
        }

        $this->line("DB guard OK: {$db} @ {$host}:{$port}");

        return true;
    }

    private function resolveTargetUser(): ?PlatformUser
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if ($email !== '') {
            if ($this->isSmokeOrDemoEmail($email)) {
                $this->error('Smoke/demo emails are refused.');

                return null;
            }
            $user = PlatformUser::on('mysql')->whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $user) {
                $this->error('No local PlatformUser with that email.');

                return null;
            }

            return $user;
        }

        $candidates = PlatformUser::on('mysql')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (PlatformUser $u) => ! $this->isSmokeOrDemoEmail((string) $u->email))
            ->values();

        if ($candidates->count() !== 1) {
            $this->error('Provide {email} — found '.$candidates->count().' non-demo active candidate(s).');
            foreach ($candidates as $c) {
                $this->line(' - id='.$c->getKey().' '.$this->maskEmail((string) $c->email));
            }

            return null;
        }

        return $candidates->first();
    }

    private function isSmokeOrDemoEmail(string $email): bool
    {
        $e = strtolower($email);

        return str_contains($e, 'smoke')
            || str_ends_with($e, '@binaa.test')
            || str_starts_with($e, 'nope-')
            || str_contains($e, 'trial-')
            || str_contains($e, 'auth-smoke');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }
        $keep = max(1, min(2, strlen($local)));

        return substr($local, 0, $keep).str_repeat('*', max(3, strlen($local) - $keep)).'@'.$domain;
    }
}
