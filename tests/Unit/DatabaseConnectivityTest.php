<?php

/**
 * DatabaseConnectivityTest
 *
 * Non-destructive connectivity-only check for the test database.
 * Does NOT run migrations, modify data, or use RefreshDatabase.
 *
 * Verifies:
 *  - Host:     127.0.0.1
 *  - Port:     3307
 *  - Database: binaa2_testing
 *  - Server:   MySQL 9.3.x
 *  - Account:  phpunit_3307
 *  - No fallback to port 3306 or 33060
 *
 * Authorization: API-GAP-M2-01 connectivity preflight
 */

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectivityTest extends TestCase
{
    /**
     * Verify the 'mysql' connection targets 127.0.0.1:3307 / binaa2_testing.
     */
    public function test_mysql_connection_targets_correct_endpoint(): void
    {
        $row = DB::connection('mysql')
            ->selectOne('SELECT @@hostname AS host, @@port AS port, DATABASE() AS db, VERSION() AS ver, USER() AS usr');

        $this->assertNotNull($row, 'Query returned no result');

        $port = (int) $row->port;
        $db   = $row->db;
        $ver  = $row->ver;
        $usr  = $row->usr;

        // Port must be 3307 — never 3306 or 33060
        $this->assertSame(3307, $port,
            "Expected port 3307, got {$port} — possible fallback to protected instance");

        // Database must be binaa2_testing
        $this->assertSame('binaa2_testing', $db,
            "Expected database binaa2_testing, got {$db}");

        // Server must be MySQL 9.3.x
        $this->assertStringStartsWith('9.3.', $ver,
            "Expected MySQL 9.3.x, got {$ver}");

        // Authenticated user must be phpunit_3307
        $this->assertStringStartsWith('phpunit_3307@', $usr,
            "Expected phpunit_3307@..., got {$usr}");
    }

    /**
     * Verify the 'tenant' connection also targets 127.0.0.1:3307 / binaa2_testing.
     */
    public function test_tenant_connection_targets_correct_endpoint(): void
    {
        $row = DB::connection('tenant')
            ->selectOne('SELECT @@port AS port, DATABASE() AS db, VERSION() AS ver, USER() AS usr');

        $this->assertNotNull($row, 'Tenant connection query returned no result');

        $port = (int) $row->port;
        $db   = $row->db;
        $ver  = $row->ver;
        $usr  = $row->usr;

        $this->assertSame(3307, $port,
            "Tenant connection: expected port 3307, got {$port}");

        $this->assertSame('binaa2_testing', $db,
            "Tenant connection: expected database binaa2_testing, got {$db}");

        $this->assertStringStartsWith('9.3.', $ver,
            "Tenant connection: expected MySQL 9.3.x, got {$ver}");

        $this->assertStringStartsWith('phpunit_3307@', $usr,
            "Tenant connection: expected phpunit_3307@..., got {$usr}");
    }

    public function test_provisioning_connection_is_local_non_root_and_has_no_global_privileges_or_grant_option(): void
    {
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'tenant:provision-registration'];

        try {
            $this->app->make(\App\Foundation\ProvisioningConnectionAccess::class)
                ->runForRegistrationCommand(function (): void {
                    foreach (['tenant', 'mysql_provisioning'] as $name) {
                        $connection = DB::connection($name);
                        $row = $connection->selectOne(
                            'SELECT @@port AS port, DATABASE() AS db, VERSION() AS ver, CURRENT_USER() AS usr'
                        );

                        $this->assertNotNull($row, "Connection {$name} returned no result.");
                        $this->assertSame(3307, (int) $row->port, "Connection {$name} is not on isolated port 3307.");
                        $this->assertSame('binaa2_testing', $row->db);
                        $this->assertStringStartsWith('9.3.', $row->ver);
                        $this->assertFalse(str_starts_with(strtolower($row->usr), 'root@'));

                        $grantRows = $connection->select('SHOW GRANTS');
                        $grants = array_map(
                            static fn (object $grantRow): string => (string) array_values((array) $grantRow)[0],
                            $grantRows,
                        );
                        $this->assertNotEmpty($grants);
                        $this->assertDoesNotMatchRegularExpression('/\bGRANT OPTION\b/i', implode("\n", $grants));

                        foreach ($grants as $grant) {
                            if (preg_match('/\bON\s+\*\.\*/i', $grant)) {
                                $this->assertMatchesRegularExpression('/^GRANT USAGE ON \*\.\*/i', $grant);
                            }
                        }
                    }
                });
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }
        }
    }

    /**
     * Confirm no connection resolves to port 3306 (protected instance).
     */
    public function test_no_connection_uses_protected_port_3306(): void
    {
        foreach (['mysql', 'tenant'] as $conn) {
            $row = DB::connection($conn)
                ->selectOne('SELECT @@port AS port');

            $this->assertNotNull($row);
            $this->assertNotEquals(3306, (int) $row->port,
                "Connection '{$conn}' resolved to protected port 3306");
            $this->assertNotEquals(33060, (int) $row->port,
                "Connection '{$conn}' resolved to protected port 33060");
        }
    }
}
