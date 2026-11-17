<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProvisioningConnectionAccess
{
    private const COMMAND = 'tenant:provision-registration';

    private bool $authorized = false;

    private int $restrictedExecutionDepth = 0;

    public function runForRegistrationCommand(callable $callback): mixed
    {
        if (! $this->isAuthorizedCommandInvocation() || $this->authorized) {
            throw $this->accessDenied();
        }

        $connectionConfig = config('database.provisioning_connection');
        if (! is_array($connectionConfig) || ($connectionConfig['driver'] ?? null) !== 'mysql_provisioning') {
            throw new RuntimeException('Provisioning database access is unavailable.');
        }

        DB::purge('mysql_provisioning');
        config(['database.connections.mysql_provisioning' => $connectionConfig]);
        $this->authorized = true;

        try {
            return $callback();
        } finally {
            try {
                DB::purge('mysql_provisioning');
            } finally {
                $connections = config('database.connections', []);
                unset($connections['mysql_provisioning']);
                config(['database.connections' => $connections]);
                $this->authorized = false;
            }
        }
    }

    public function assertAuthorized(): void
    {
        if (
            ! $this->authorized
            || $this->restrictedExecutionDepth > 0
            || ! $this->isAuthorizedCommandInvocation()
        ) {
            throw $this->accessDenied();
        }
    }

    public function enterRestrictedExecution(): void
    {
        $this->restrictedExecutionDepth++;
    }

    public function leaveRestrictedExecution(): void
    {
        $this->restrictedExecutionDepth = max(0, $this->restrictedExecutionDepth - 1);
    }

    protected function isAuthorizedCommandInvocation(): bool
    {
        return PHP_SAPI === 'cli'
            && app()->runningInConsole()
            && ($_SERVER['argv'][1] ?? null) === self::COMMAND;
    }

    private function accessDenied(): RuntimeException
    {
        return new RuntimeException(
            'Provisioning database access is restricted to the tenant:provision-registration command.'
        );
    }
}
