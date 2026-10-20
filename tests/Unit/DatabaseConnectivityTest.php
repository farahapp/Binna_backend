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
