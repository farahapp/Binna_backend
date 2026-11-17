<?php

declare(strict_types=1);

namespace App\Foundation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TenantSchemaGrantor — DEC-MYSQL-001 (Path A)
 *
 * Responsibilities:
 *   1. Verify the exact per-schema privileges granted externally to the runtime user.
 *   2. Block tenant activation until those grants are present.
 *
 * The approved operator performs GRANT outside Laravel. This class uses the
 * `mysql_provisioning` connection only to read INFORMATION_SCHEMA. The runtime
 * connection (`tenant`) must NOT have GRANT OPTION or DDL privileges.
 *
 * The database connection itself is available only within the explicitly
 * authorized tenant:provision-registration command scope.
 *
 * RUNTIME PRIVILEGES VERIFIED (DML only — no DDL):
 *   SELECT, INSERT, UPDATE, DELETE
 *
 * DDL privileges (CREATE TABLE, DROP, ALTER, CREATE INDEX) are intentionally
 * excluded from the runtime user grant. Migrations are run via the provisioning
 * connection during tenant setup only.
 */
class TenantSchemaGrantor
{
    /**
     * Runtime DML privileges required per tenant schema.
     * Migration-only privileges such as INDEX and REFERENCES are excluded.
     */
    private const RUNTIME_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
    ];

    /**
     * @throws RuntimeException if called outside CLI context
     */
    public function __construct()
    {
        $this->assertCliContext();
    }

    /**
     * Verify the exact per-schema privileges applied externally to the runtime user.
     *
     * @param  string  $schemaName   Validated tenant schema name (binaa2_tenant_*)
     * @param  string  $runtimeUser  MySQL username of the runtime connection (DB_USERNAME)
     * @param  string  $runtimeHost  MySQL host for the runtime user (default '%')
     * @return void
     *
     * @throws RuntimeException if configuration or external-grant verification fails
     */
    public function verifyGrantedPrivileges(string $schemaName, string $runtimeUser, string $runtimeHost = '%'): void
    {
        $this->assertSafeSchemaName($schemaName);
        $this->assertSafeIdentifier($runtimeUser, 'runtime user');
        $this->assertSafeIdentifier($runtimeHost, 'runtime host');

        $this->assertProvisioningConnectionConfigured();

        // The authorized external operator applies GRANT; Laravel only verifies it.
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
                .                 'Grant verification is not permitted from HTTP request context.'
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
        $username = config('database.provisioning_connection.username');
        $password = config('database.provisioning_connection.password');

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
        // We collect privilege and grantability rows for the exact
        // GRANTEE+TABLE_SCHEMA combination and enforce the approved Runtime set.
        // A single row per privilege is returned (not one row with Y/N columns).
        // An empty result set means no grant exists for this user+schema pair.
        $grantee = sprintf("'%s'@'%s'", $runtimeUser, $runtimeHost);

        $rows = DB::connection('mysql_provisioning')->select(
            'SELECT PRIVILEGE_TYPE, IS_GRANTABLE
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

        $missing = array_values(array_diff(self::RUNTIME_PRIVILEGES, $granted));
        $unexpected = array_values(array_diff($granted, self::RUNTIME_PRIVILEGES));
        $grantable = array_filter(
            $rows,
            static fn (object $r): bool => strtoupper((string) ($r->IS_GRANTABLE ?? '')) !== 'NO',
        );

        if (! empty($missing)) {
            throw new RuntimeException(
                "GRANT verification failed for schema [{$schemaName}]: "
                . 'Missing privileges: ' . implode(', ', $missing) . '. '
                . 'Tenant will remain in pending status.'
            );
        }

        if (! empty($unexpected)) {
            throw new RuntimeException(
                "Runtime privilege verification failed for schema [{$schemaName}]: "
                . 'Unexpected privileges: ' . implode(', ', array_unique($unexpected)) . '. '
                . 'Tenant will remain in pending status.'
            );
        }

        if (! empty($grantable)) {
            throw new RuntimeException(
                "Runtime privilege verification failed for schema [{$schemaName}]: "
                . 'A schema privilege is grantable or its grantability could not be verified. '
                . 'Tenant will remain in pending status.'
            );
        }
    }
}
