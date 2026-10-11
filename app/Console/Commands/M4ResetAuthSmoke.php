<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\Tenant;
use App\IAM\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * M4ResetAuthSmoke — temporary, narrow utility.
 *
 * Resets the password for auth-smoke@binaa.test in the auth-smoke tenant.
 * Scope: password column only, inside a transaction, no token/role/env changes.
 * Self-destructs after single use (caller must delete this file).
 *
 * SECURITY: password is accepted via hidden prompt only; never printed/logged.
 *
 * NON-INTERACTIVE PATH (test/local environments only):
 *   Set the environment variable M4_SMOKE_PW to the desired password before
 *   running the command. The variable is read once, never echoed or logged,
 *   and must NOT be persisted in .env or any configuration file.
 *
 *   Example (one-time, in-process only — value never touches shell history):
 *     $env:M4_SMOKE_PW="<password>"; php artisan dev:m4-reset-auth-smoke; Remove-Item Env:M4_SMOKE_PW
 *
 *   Guards: this path is refused unless APP_ENV is 'local' or 'testing',
 *   and the target account is exactly auth-smoke@binaa.test in the auth-smoke tenant.
 */
class M4ResetAuthSmoke extends Command
{
    protected $signature   = 'dev:m4-reset-auth-smoke';
    protected $description = 'Temporary M4 utility: reset auth-smoke@binaa.test password (delete after use)';

    /** Environment variable name for non-interactive input. Never printed. */
    private const ENV_VAR = 'M4_SMOKE_PW';

    /** Allowed APP_ENV values for the non-interactive path. */
    private const ALLOWED_ENVS = ['local', 'testing'];

    /** The only account this command may touch. */
    private const TARGET_EMAIL = 'auth-smoke@binaa.test';

    /** The only tenant slug this command may touch. */
    private const TARGET_SLUG = 'auth-smoke';

    public function handle(): int
    {
        // 0. Environment guard — non-interactive path is refused in production.
        $appEnv   = app()->environment();
        $envValue = getenv(self::ENV_VAR);
        $nonInteractive = ($envValue !== false && $envValue !== '');

        if ($nonInteractive && ! in_array($appEnv, self::ALLOWED_ENVS, true)) {
            $this->error(
                'Non-interactive path refused: APP_ENV is "' . $appEnv . '". '
                . 'Allowed only in: ' . implode(', ', self::ALLOWED_ENVS) . '.'
            );
            return self::FAILURE;
        }

        // 1. Resolve tenant
        $tenant = Tenant::where('slug', self::TARGET_SLUG)->first();
        if (! $tenant) {
            $this->error('Tenant ' . self::TARGET_SLUG . ' not found.');
            return self::FAILURE;
        }
        if ($tenant->status !== 'active') {
            $this->error('Tenant ' . self::TARGET_SLUG . ' is not active (status=' . $tenant->status . ').');
            return self::FAILURE;
        }
        $this->line('Tenant: id=' . $tenant->id . ' schema=' . $tenant->schema_name . ' status=' . $tenant->status);

        // 2. Switch tenant connection to the correct schema
        $base = config('database.connections.mysql');
        config(['database.connections.tenant' => array_merge($base, ['database' => $tenant->schema_name])]);
        DB::purge('tenant');
        DB::reconnect('tenant');

        // 3. Verify user exists (account guard)
        $user = User::on('tenant')->where('email', self::TARGET_EMAIL)->first();
        if (! $user) {
            $this->error('User ' . self::TARGET_EMAIL . ' not found in schema ' . $tenant->schema_name . '.');
            return self::FAILURE;
        }
        $this->line('User found: id=' . $user->id);

        // 4. Obtain password — env-var path (non-interactive) or hidden prompt (interactive).
        if ($nonInteractive) {
            $password = $envValue;
            $this->line('Using non-interactive input (env-var). Value not echoed.');
        } else {
            $password = $this->secret('Enter new password for ' . self::TARGET_EMAIL);
        }

        if (! $password || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        // 5. Update password only, inside a transaction
        DB::connection('tenant')->transaction(function () use ($user, $password) {
            $user->password = Hash::make($password);
            $user->save();
        });

        // 6. Verify: re-fetch and check hash (no secret output)
        $fresh = User::on('tenant')->where('email', self::TARGET_EMAIL)->first();
        if (! $fresh || ! Hash::check($password, $fresh->password)) {
            $this->error('Verification FAILED: hash check did not pass.');
            return self::FAILURE;
        }

        $this->info('Password reset and verified successfully. Delete this command file now.');
        return self::SUCCESS;
    }
}
