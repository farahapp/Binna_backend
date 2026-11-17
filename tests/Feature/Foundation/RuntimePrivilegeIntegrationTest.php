<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Foundation\ProvisioningConnectionAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RuntimePrivilegeIntegrationTest extends TestCase
{
    #[Test]
    public function local_runtime_account_capabilities_are_audited_on_two_test_schemas(): void
    {
        $this->assertLocalEndpoint('mysql', 'binaa2_testing');
        $this->assertLocalEndpoint('tenant', 'binaa2_testing');

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $schemas = [
            "binaa2_tenant_audit_a_{$suffix}",
            "binaa2_tenant_audit_b_{$suffix}",
        ];
        $ownedSchemas = [];
        $table = 'runtime_privilege_probe';
        $ddlProbeTable = "runtime_ddl_probe_{$suffix}";
        $observation = [];

        try {
            $this->withProvisioningAccess(function () use ($schemas, $table, &$ownedSchemas): void {
                $this->assertLocalEndpoint('mysql_provisioning', 'binaa2_testing');
                foreach ($schemas as $schema) {
                    $this->assertMatchesRegularExpression('/^binaa2_tenant_[a-z0-9_]+$/', $schema);
                    $this->assertNull(DB::connection('mysql')->selectOne(
                        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                        [$schema],
                    ));
                    DB::connection('mysql_provisioning')->statement(
                        "CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                    );
                    $ownedSchemas[] = $schema;
                    DB::connection('mysql_provisioning')->statement(
                        "CREATE TABLE `{$schema}`.`{$table}` (id INT PRIMARY KEY, payload VARCHAR(100) NOT NULL)",
                    );
                    DB::connection('mysql_provisioning')->insert(
                        "INSERT INTO `{$schema}`.`{$table}` (id, payload) VALUES (?, ?)",
                        [1, $schema],
                    );
                }
            });

            config(['database.connections.tenant.database' => $schemas[0]]);
            DB::purge('tenant');
            $runtime = DB::connection('tenant');
            $identity = $runtime->selectOne(
                'SELECT CURRENT_USER() AS account, @@port AS port, DATABASE() AS database_name',
            );
            $this->assertSame(3307, (int) $identity->port);
            $this->assertSame($schemas[0], $identity->database_name);
            $this->assertStringStartsWith('phpunit_3307@', (string) $identity->account);

            $grantMetadata = $this->readSanitizedGrants($runtime->select('SHOW GRANTS'));
            $globalRows = $runtime->select(
                'SELECT PRIVILEGE_TYPE, IS_GRANTABLE
                 FROM information_schema.USER_PRIVILEGES
                 WHERE GRANTEE = ?',
                [$this->grantee((string) $identity->account)],
            );
            $globalPrivileges = array_values(array_unique(array_map(
                static fn (object $row): string => strtoupper((string) $row->PRIVILEGE_TYPE),
                $globalRows,
            )));
            sort($globalPrivileges);
            $globalGrantOption = array_filter(
                $globalRows,
                static fn (object $row): bool => strtoupper((string) $row->IS_GRANTABLE) === 'YES',
            ) !== [];

            $localRead = $runtime->selectOne(
                "SELECT payload FROM `{$schemas[0]}`.`{$table}` WHERE id = ?",
                [1],
            );
            $this->assertSame($schemas[0], $localRead->payload);

            $runtime->beginTransaction();
            try {
                $runtime->insert(
                    "INSERT INTO `{$schemas[0]}`.`{$table}` (id, payload) VALUES (?, ?)",
                    [2, 'runtime-local-write'],
                );
                $runtime->update(
                    "UPDATE `{$schemas[0]}`.`{$table}` SET payload = ? WHERE id = ?",
                    ['runtime-local-update', 2],
                );
                $runtime->delete(
                    "DELETE FROM `{$schemas[0]}`.`{$table}` WHERE id = ?",
                    [2],
                );
            } finally {
                if ($runtime->transactionLevel() > 0) {
                    $runtime->rollBack();
                }
            }

            $observation['cross_schema_read'] = $this->databaseOperationAllowed(
                fn () => $runtime->selectOne(
                    "SELECT payload FROM `{$schemas[1]}`.`{$table}` WHERE id = ?",
                    [1],
                ),
            );
            $observation['cross_schema_insert'] = $this->inRolledBackTransaction(
                $runtime,
                fn () => $runtime->insert(
                    "INSERT INTO `{$schemas[1]}`.`{$table}` (id, payload) VALUES (?, ?)",
                    [2, 'synthetic-cross-schema-write'],
                ),
            );
            $observation['cross_schema_update'] = $this->inRolledBackTransaction(
                $runtime,
                fn () => $runtime->update(
                    "UPDATE `{$schemas[1]}`.`{$table}` SET payload = ? WHERE id = ?",
                    ['synthetic-cross-schema-update', 1],
                ),
            );
            $observation['cross_schema_delete'] = $this->inRolledBackTransaction(
                $runtime,
                fn () => $runtime->delete(
                    "DELETE FROM `{$schemas[1]}`.`{$table}` WHERE id = ?",
                    [1],
                ),
            );
            $observation['tenant_ddl'] = $this->databaseOperationAllowed(
                fn () => $runtime->statement(
                    "CREATE TABLE `{$schemas[0]}`.`{$ddlProbeTable}` (id INT PRIMARY KEY)",
                ),
            );
            $observation['global_privileges'] = $globalPrivileges;
            $observation['global_grant_option'] = $globalGrantOption;
            $observation['sanitized_grants'] = $grantMetadata;

            fwrite(STDOUT, 'Local Runtime MySQL audit (test account only): '.json_encode([
                'account' => explode('@', (string) $identity->account, 2)[0],
                'port' => (int) $identity->port,
                'tenant_ddl_allowed' => $observation['tenant_ddl'],
                'cross_schema' => [
                    'read' => $observation['cross_schema_read'],
                    'insert' => $observation['cross_schema_insert'],
                    'update' => $observation['cross_schema_update'],
                    'delete' => $observation['cross_schema_delete'],
                ],
                'global_privileges' => $globalPrivileges,
                'global_grant_option' => $globalGrantOption,
                'sanitized_grants' => $grantMetadata,
                'grant_statement_executed' => false,
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            config(['database.connections.tenant.database' => config('database.connections.mysql.database')]);
            DB::purge('tenant');
            if ($ownedSchemas !== []) {
                $this->withProvisioningAccess(function () use ($ownedSchemas): void {
                    $this->assertLocalEndpoint('mysql_provisioning', 'binaa2_testing');
                    foreach ($ownedSchemas as $schema) {
                        DB::connection('mysql_provisioning')->statement(
                            "DROP DATABASE IF EXISTS `{$schema}`",
                        );
                    }
                });
            }
        }
    }

    private function assertLocalEndpoint(string $connectionName, string $database): void
    {
        $endpoint = DB::connection($connectionName)->selectOne(
            'SELECT @@port AS port, DATABASE() AS database_name',
        );
        $this->assertSame(3307, (int) $endpoint->port);
        $this->assertSame($database, $endpoint->database_name);
    }

    private function withProvisioningAccess(callable $callback): mixed
    {
        $hadArgv = array_key_exists('argv', $_SERVER);
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];
        try {
            return $this->app->make(ProvisioningConnectionAccess::class)
                ->runForRegistrationCommand($callback);
        } finally {
            if ($hadArgv) {
                $_SERVER['argv'] = $previousArgv;
            } else {
                unset($_SERVER['argv']);
            }
        }
    }

    private function databaseOperationAllowed(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if ($state !== '42000' && ! in_array($driverCode, [1044, 1142, 1143], true)) {
                throw $exception;
            }

            return false;
        }
    }

    private function inRolledBackTransaction($connection, callable $operation): bool
    {
        $connection->beginTransaction();
        try {
            return $this->databaseOperationAllowed($operation);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
    }

    private function readSanitizedGrants(array $grantRows): array
    {
        $summary = [];
        foreach ($grantRows as $row) {
            $grant = (string) array_values((array) $row)[0];
            if (! preg_match('/^GRANT (.+?) ON (.+?) TO /i', $grant, $matches)) {
                throw new \RuntimeException('Unexpected SHOW GRANTS format; raw grant withheld.');
            }
            $privileges = array_map('trim', explode(',', $matches[1]));
            sort($privileges);
            $summary[] = [
                'privileges' => $privileges,
                'scope' => str_replace('`', '', $matches[2]),
                'grant_option' => (bool) preg_match('/WITH GRANT OPTION/i', $grant),
            ];
        }

        return $summary;
    }

    private function grantee(string $currentUser): string
    {
        [$user, $host] = array_pad(explode('@', $currentUser, 2), 2, '');

        return sprintf("'%s'@'%s'", $user, $host);
    }
}
