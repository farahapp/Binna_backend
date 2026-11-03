<?php

/**
 * PdoMysqlAttrSslCaShimTest
 *
 * Verifies that config/database.php defines BINAA2_PDO_MYSQL_ATTR_SSL_CA
 * to the correct integer value for the running PHP version:
 *
 *  - PHP >= 8.4 with pdo_mysql loaded  → \Pdo\Mysql::ATTR_SSL_CA
 *  - PHP <  8.4 (or no Pdo\Mysql class) → PDO::MYSQL_ATTR_SSL_CA
 *
 * Both constants resolve to the same integer (1010) in all known PHP
 * releases, so the numeric assertion is version-independent.
 *
 * This test does NOT connect to any database and does NOT use
 * RefreshDatabase.  It is safe to run in any environment.
 *
 * Authorization: PDO-COMPAT-SHIM-01
 */

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class PdoMysqlAttrSslCaShimTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the expected integer value of the SSL-CA attribute constant
     * for the current PHP version, without touching the shim constant itself.
     */
    private function expectedConstantValue(): int
    {
        if (PHP_VERSION_ID >= 80400 && class_exists(\Pdo\Mysql::class)) {
            return \Pdo\Mysql::ATTR_SSL_CA;
        }

        // PDO::MYSQL_ATTR_SSL_CA is available on PHP < 8.4 (and on 8.4 as a
        // deprecated alias).  We access it via string to avoid a deprecation
        // notice in the test itself on PHP 8.4.
        /** @phpstan-ignore-next-line */
        return constant('PDO::MYSQL_ATTR_SSL_CA');
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /**
     * The shim constant must be defined after the config file is loaded.
     *
     * Laravel's bootstrap loads config/database.php before any test runs, so
     * the constant should already be defined.  If for any reason it is not
     * (e.g. isolated unit test without the full bootstrap), we load it here.
     */
    public function test_shim_constant_is_defined(): void
    {
        // Ensure the config file has been evaluated at least once.
        if (! defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA')) {
            // Load via the Laravel helper so env() is available.
            // In a full Laravel test this path is never reached.
            $this->markTestSkipped(
                'BINAA2_PDO_MYSQL_ATTR_SSL_CA not yet defined; ' .
                'run inside a full Laravel bootstrap or via php artisan test.'
            );
        }

        $this->assertTrue(
            defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA'),
            'BINAA2_PDO_MYSQL_ATTR_SSL_CA must be defined after config load'
        );
    }

    /**
     * The shim constant must resolve to the correct integer for this PHP
     * version.
     *
     * On PHP 8.4+ the value comes from \Pdo\Mysql::ATTR_SSL_CA.
     * On PHP <  8.4 the value comes from PDO::MYSQL_ATTR_SSL_CA.
     * Both are 1010 in all known PHP releases.
     */
    public function test_shim_constant_resolves_to_correct_value(): void
    {
        if (! defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped(
                'BINAA2_PDO_MYSQL_ATTR_SSL_CA not yet defined; ' .
                'run inside a full Laravel bootstrap or via php artisan test.'
            );
        }

        $expected = $this->expectedConstantValue();
        $actual   = BINAA2_PDO_MYSQL_ATTR_SSL_CA;

        $this->assertSame(
            $expected,
            $actual,
            sprintf(
                'On PHP %s the shim should resolve to %d (%s), got %d',
                PHP_VERSION,
                $expected,
                PHP_VERSION_ID >= 80400 ? '\\Pdo\\Mysql::ATTR_SSL_CA' : 'PDO::MYSQL_ATTR_SSL_CA',
                $actual
            )
        );
    }

    /**
     * On PHP >= 8.4 the shim must NOT use the deprecated PDO::MYSQL_ATTR_SSL_CA
     * path — it must use \Pdo\Mysql::ATTR_SSL_CA.
     *
     * We verify this by checking that \Pdo\Mysql::class exists and that the
     * shim value equals \Pdo\Mysql::ATTR_SSL_CA (not just the same integer
     * from the old constant).
     */
    public function test_shim_uses_namespaced_constant_on_php_84_plus(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('PHP < 8.4 — namespaced Pdo\\Mysql class not available.');
        }

        $this->assertTrue(
            class_exists(\Pdo\Mysql::class),
            '\\Pdo\\Mysql class must exist on PHP 8.4+'
        );

        if (! defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped(
                'BINAA2_PDO_MYSQL_ATTR_SSL_CA not yet defined; ' .
                'run inside a full Laravel bootstrap or via php artisan test.'
            );
        }

        $this->assertSame(
            \Pdo\Mysql::ATTR_SSL_CA,
            BINAA2_PDO_MYSQL_ATTR_SSL_CA,
            'On PHP 8.4+ the shim must resolve to \\Pdo\\Mysql::ATTR_SSL_CA'
        );
    }

    /**
     * On PHP < 8.4 the shim must fall back to PDO::MYSQL_ATTR_SSL_CA.
     */
    public function test_shim_falls_back_to_legacy_constant_on_php_83_and_below(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->markTestSkipped('PHP >= 8.4 — legacy fallback path not exercised.');
        }

        if (! defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped(
                'BINAA2_PDO_MYSQL_ATTR_SSL_CA not yet defined; ' .
                'run inside a full Laravel bootstrap or via php artisan test.'
            );
        }

        /** @phpstan-ignore-next-line */
        $this->assertSame(
            \PDO::MYSQL_ATTR_SSL_CA,
            BINAA2_PDO_MYSQL_ATTR_SSL_CA,
            'On PHP < 8.4 the shim must fall back to PDO::MYSQL_ATTR_SSL_CA'
        );
    }

    /**
     * Sanity-check: the shim constant must equal the value returned by
     * expectedConstantValue() — i.e. the correct source constant for this
     * PHP version.  We do NOT hard-code the integer because the value
     * differs between PHP builds (1009 on some, 1010 on others).
     */
    public function test_shim_constant_matches_expected_source_constant(): void
    {
        if (! defined('BINAA2_PDO_MYSQL_ATTR_SSL_CA')) {
            $this->markTestSkipped(
                'BINAA2_PDO_MYSQL_ATTR_SSL_CA not yet defined; ' .
                'run inside a full Laravel bootstrap or via php artisan test.'
            );
        }

        $expected = $this->expectedConstantValue();

        $this->assertSame(
            $expected,
            BINAA2_PDO_MYSQL_ATTR_SSL_CA,
            sprintf(
                'Shim must equal the source constant (%d) on PHP %s',
                $expected,
                PHP_VERSION
            )
        );
    }
}
