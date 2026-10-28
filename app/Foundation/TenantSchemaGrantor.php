<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TenantSchemaGrantor — DEC-MYSQL-001 (Path A)
 *
 * Responsibilities:
 *   1. GRANT exact per-schema privileges to the runtime user on a newly
 *      created tenant schema, using the provisioning connection.
 *   2. Verify the grants were applied before the tenant is activated.
 *
 * GRANT is performed via the `mysql_provisioning` connection which requires
 * GRANT OPTION. The runtime connection (`tenant`) must NOT have GRANT OPTION
 * or any DDL privileges (CREATE TABLE, DROP, ALTER, etc.).
 *
 * CLI-ONLY GUARD:
 *   This class MUST only be instantiated from CLI context (Artisan commands).
 *   Calling it from an HTTP request throws a RuntimeException immediately.
 *
 * EXACT PRIVILEGES GRANTED (DML only — no DDL):
 *   SELECT, INSERT, UPDATE, DELETE, INDEX, REFERENCES
 *
 * DDL privileges (CREATE TABLE, DROP, ALTER, CREATE INDEX) are intentionally
 * excluded from the runtime user grant. Migrations are run via the provisioning
 * connection during tenant setup only.
 */
class TenantSchemaGrantor
{
    /**
     * Exact DML privileges granted to the runtime user per tenant schema.
     * DDL privileges are intentionally excluded.
     */
    private const RUNTIME_PRIVILEGES = 'SELECT, INSERT, UPDATE, DELETE, INDEX, REFERENCES';

    /**
     * @throws RuntimeException if called outside CLI context
     */
    public function __construct()
    {
        $this->assertCliContext();
    }

    /**
     * Grant exact per-schema privileges to the runtime user on the given schema,
     * then verify the grants were applied.
     *
     * @param  string  $schemaName   Validated tenant schema name (binaa2_tenant_*)
     * @param  string  $runtimeUser  MySQL username of the runtime connection (DB_USERNAME)
     * @param  string  $runtimeHost  MySQL host for the runtime user (default '%')
     * @return void
     *
     * @throws RuntimeException if GRANT fails or verification fails
     */
    public function grantAndVerify(string $schemaName, string $runtimeUser, string $runtimeHost = '%'): void
    {
        $this->assertSafeSchemaName($schemaName);
        $this->assertSafeIdentifier($runtimeUser, 'runtime user');
        $this->assertSafeIdentifier($runtimeHost, 'runtime host');

        $this->assertProvisioningConnectionConfigured();

        // Execute GRANT via provisioning connection (requires GRANT OPTION).
        $this->executeGrant($schemaName, $runtimeUser, $runtimeHost);

        // Note: FLUSH PRIVILEGES is intentionally omitted.
        // GRANT/REVOKE/CREATE USER statements apply immediately in MySQL 8+
        // without requiring FLUSH. Calling FLUSH PRIVILEGES after GRANT causes
        // an implicit commit that breaks DatabaseTransactions in tests and
        // requires the RELOAD privilege on the provisioning account unnecessarily.

        // Verify grants were applied — tenant stays pending if this fails.
        $this->verifyGrant($schemaName, $runtimeUser, $runtimeHost);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertCliContext(): void
    {
        if (! $this->isRunningInConsole()) {
            throw new RuntimeException(
                'TenantSchemaGrantor MUST only be used from CLI context (Artisan commands). '
                . 'GRANT operations are not permitted from HTTP request context.'
            );
        }
    }

    /**
     * Returns true if running in console context.
     * Extracted as a protected method to allow test overriding via anonymous subclass.
     */
    protected function isRunningInConsole(): bool
    {
        return app()->runningInConsole();
    }

    private function assertProvisioningConnectionConfigured(): void
    {
        $username = config('database.connections.mysql_provisioning.username');
        $password = config('database.connections.mysql_provisioning.password');

        if (empty($username) || empty($password)) {
            throw new RuntimeException(
                'Provisioning connection is not configured. '
                . 'Set PROVISIONING_DB_USERNAME and PROVISIONING_DB_PASSWORD environment variables. '
                . 'These must be set explicitly — no fallback to runtime credentials is allowed.'
            );
        }
    }

    private function assertSafeSchemaName(string $schemaName): void
    {
        if (! preg_match('/^binaa2_tenant_[a-z0-9_]+$/', $schemaName)) {
            throw new RuntimeException(
                "Unsafe schema name [{$schemaName}] rejected. "
                . 'Schema name must match pattern: binaa2_tenant_[a-z0-9_]+'
            );
        }
    }

    private function assertSafeIdentifier(string $value, string $label): void
    {
        // Allow alphanumeric, underscore, hyphen, dot, @, % — reject anything else.
        if (! preg_match('/^[a-zA-Z0-9_\-\.@%]+$/', $value)) {
            throw new RuntimeException(
                "Unsafe {$label} [{$value}] rejected. "
                . 'Only alphanumeric, underscore, hyphen, dot, @, % are allowed.'
            );
        }
    }

    private function executeGrant(string $schemaName, string $runtimeUser, string $runtimeHost): void
    {
        // Backtick-quote schema name (validated above). User/host use single-quote quoting.
        $sql = sprintf(
            "GRANT %s ON `%s`.* TO '%s'@'%s'",
            self::RUNTIME_PRIVILEGES,
            $schemaName,
            $runtimeUser,
            $runtimeHost
        );

        try {
            DB::connection('mysql_provisioning')->statement($sql);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "GRANT failed for schema [{$schemaName}] to user [{$runtimeUser}@{$runtimeHost}]: "
                . $e->getMessage(),
                previous: $e,
            );
        }
    }

    private function verifyGrant(string $schemaName, string $runtimeUser, string $runtimeHost): void
    {
        // Query information_schema.SCHEMA_PRIVILEGES — readable by the provisioning
        // account without SELECT on mysql.db (which requires a privilege not held by
        // binaa2_provisioning@127.0.0.1 on MySQL 8.4, causing error 1142).
        //
        // Why information_schema.SCHEMA_PRIVILEGES instead of mysql.db:
        //   mysql.db requires SELECT privilege on the mysql system schema, which the
        //   provisioning account does NOT hold (SHOW GRANTS confirms no mysql.* grant).
        //   information_schema.SCHEMA_PRIVILEGES is a standard INFORMATION_SCHEMA view
        //   that reflects the same per-schema grants and is always readable by the
        //   current session without additional privileges (MySQL 8.0+/8.4 behaviour).
        //
        // GRANTEE format in information_schema is 'user'@'host' (single-quoted).
        // TABLE_SCHEMA matches the schema name exactly (case-insensitive on most
        // platforms, but we pass the exact name used in the GRANT statement).
        //
        // We collect all PRIVILEGE_TYPE rows for the exact GRANTEE+TABLE_SCHEMA
        // combination and verify all six required privileges are present.
        // A single row per privilege is returned (not one row with Y/N columns).
        // An empty result set means no grant exists for this user+schema pair.
        $grantee = sprintf("'%s'@'%s'", $runtimeUser, $runtimeHost);

        $rows = DB::connection('mysql_provisioning')->select(
            'SELECT PRIVILEGE_TYPE
             FROM   information_schema.SCHEMA_PRIVILEGES
             WHERE  GRANTEE      = ?
               AND  TABLE_SCHEMA = ?',
            [$grantee, $schemaName]
        );

        // No rows means no per-schema grant exists for this user+schema pair.
        if (empty($rows)) {
            throw new RuntimeException(
                "GRANT verification failed for schema [{$schemaName}]: "
                . "No privileges found in information_schema.SCHEMA_PRIVILEGES "
                . "for user [{$runtimeUser}@{$runtimeHost}]. "
                . 'Tenant will remain in pending status.'
            );
        }

        // Collect the granted privilege types (uppercased for comparison).
        $granted = array_map(
            static fn (object $r): string => strtoupper((string) $r->PRIVILEGE_TYPE),
            $rows
        );

        // All six DML privileges that must be present (DDL intentionally excluded).
        $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'INDEX', 'REFERENCES'];

        $missing = array_values(array_diff($required, $granted));

        if (! empty($missing)) {
            throw new RuntimeException(
                "GRANT verification failed for schema [{$schemaName}]: "
                . 'Missing privileges: ' . implode(', ', $missing) . '. '
                . 'Tenant will remain in pending status.'
            );
        }
    }
}
